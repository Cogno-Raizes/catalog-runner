import 'dotenv/config';
import express from 'express';
import fetch from 'node-fetch';
import { createClient } from '@supabase/supabase-js';

const app = express();
app.use(express.json());

const supa = createClient(process.env.SUPABASE_URL, process.env.SUPABASE_KEY, { auth: { persistSession: false } });

let natToken = null;
let natTokenExpiresAt = 0;

async function natLogin() {
  // 1) Auth Natural Systems -> token 24h
  if (natToken && Date.now() < natTokenExpiresAt) return natToken;

  const res = await fetch(`${process.env.NAT_URL}/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ apiKey: process.env.NAT_API_KEY })
  });
  if (!res.ok) throw new Error(`Login Natural Systems falhou: ${res.status}`);
  const data = await res.json();
  // Assumindo { token: '...' } – guarda por 23h para margem
  natToken = data.token;
  natTokenExpiresAt = Date.now() + 23 * 60 * 60 * 1000;
  return natToken;
}

async function natGet(path) {
  const token = await natLogin();
  const res = await fetch(`${process.env.NAT_URL}${path}`, {
    headers: { Authorization: `Bearer ${token}` }
  });
  if (!res.ok) throw new Error(`GET ${path} falhou: ${res.status}`);
  return res.json();
}

async function fetchNaturalSystemsCatalog() {
  // 2) Buscar catálogo / preços / stock
  const [catalogo, precios, stock] = await Promise.all([
    natGet('/producto/getCatalogo'),
    natGet('/producto/getPrecio'),
    natGet('/producto/getStock')
  ]);

  // 3) Unificar por itemCode
  const byCode = new Map();
  const upsert = (code, patch) => {
    if (!code) return;
    const curr = byCode.get(code) || {};
    byCode.set(code, { ...curr, ...patch, itemCode: code });
  };

  for (const p of catalogo || []) {
    upsert(p.itemCode, {
      title: p.name || p.description || p.itemName,
      brand: p.brand || p.marca,
      category: p.category || p.categoria,
      raw: p
    });
  }
  for (const p of precios || []) {
    upsert(p.itemCode, { price: Number(p.price ?? p.precio) });
  }
  for (const p of stock || []) {
    upsert(p.itemCode, { stock: Number(p.stock ?? p.quantity ?? 0) });
  }

  return Array.from(byCode.values());
}

async function saveSupplierProducts(rows) {
  // 4) Guardar no Supabase
  const inserts = rows.map(r => ({
    supplier: 'natural_systems',
    item_code: r.itemCode,
    title: r.title ?? null,
    brand: r.brand ?? null,
    category: r.category ?? null,
    price: r.price ?? null,
    stock: r.stock ?? null,
    raw: r
  }));
  // Upsert pelo (supplier, item_code)
  const { error } = await supa
    .from('supplier_products')
    .upsert(inserts, { onConflict: 'supplier,item_code' });
  if (error) throw error;
}

async function shopkitFetchByReference(reference) {
  // 5) Procurar produto na Shopkit pelo SKU (reference)
  const url = `${process.env.SHOPKIT_API}/product?reference=${encodeURIComponent(reference)}&limit=1`;
  const res = await fetch(url, { headers: { 'X-API-KEY': process.env.SHOPKIT_KEY } });
  if (!res.ok) throw new Error(`Shopkit search failed ${res.status}`);
  const data = await res.json();
  // A API paginada devolve arrays; aqui normaliza
  return Array.isArray(data) ? data[0] : data;
}

function buildShopkitPayload(supp) {
  // 6) Mapear fornecedor -> payload Shopkit
  const payload = {
    title: supp.title || supp.item_code,
    price: supp.price ?? 0,
    categories: [],                  // TODO: mapeia as tuas categorias Shopkit
    reference: supp.item_code,       // usa o código do fornecedor como SKU
    images: [],                      // TODO: se o fornecedor der URLs
    stock_enabled: true,
    stock_qty: Number.isFinite(supp.stock) ? supp.stock : 0,
    stock_backorder: false,
    stock_show: true,
    taxable: true
  };
  return payload;
}

function diffForUpdate(shopkit, payload) {
  // 7) Deteção de diferenças para updates mínimos
  const diff = {};
  if (shopkit.title !== payload.title) diff.title = [shopkit.title, payload.title];
  if (Number(shopkit.price) !== Number(payload.price)) diff.price = [shopkit.price, payload.price];

  const skStock = shopkit.stock?.stock_qty ?? null;
  if (skStock !== payload.stock_qty) diff.stock_qty = [skStock, payload.stock_qty];

  // Podes comparar mais campos (images, categories, taxable, etc.)
  return diff;
}

// ========== ROTAS ==========

// 1) Sincronizar fornecedor -> BD e gerar propostas (NADA envia à Shopkit)
app.post('/sync/natural-systems', async (req, res) => {
  try {
    const supplierRows = await fetchNaturalSystemsCatalog();
    await saveSupplierProducts(supplierRows);

    // Gerar propostas para cada item (create/update)
    let proposed = 0;

    for (const supp of supplierRows) {
      const { data: mapRow } = await supa
        .from('product_mappings')
        .select('*')
        .eq('supplier', 'natural_systems')
        .eq('item_code', supp.itemCode)
        .single();

      const payload = buildShopkitPayload({
        item_code: supp.itemCode,
        title: supp.title,
        price: supp.price,
        stock: supp.stock
      });

      if (!mapRow) {
        // Tenta achar na Shopkit pelo reference= item_code (primeira vez)
        const found = await shopkitFetchByReference(supp.itemCode).catch(() => null);
        if (found?.id) {
          // Se já existir, cria mapeamento e proposta de update (se houver diff)
          await supa.from('product_mappings').upsert({
            supplier: 'natural_systems',
            item_code: supp.itemCode,
            shopkit_product_id: found.id,
            shopkit_reference: supp.itemCode,
            status: 'mapped'
          });
          const diff = diffForUpdate(found, payload);
          if (Object.keys(diff).length) {
            await supa.from('change_queue').insert({
              supplier: 'natural_systems',
              item_code: supp.itemCode,
              action: 'update',
              diff,
              payload,
              target_id: found.id
            });
            proposed++;
          }
        } else {
          // Não existe na Shopkit -> proposta de criação
          await supa.from('change_queue').insert({
            supplier: 'natural_systems',
            item_code: supp.itemCode,
            action: 'create',
            diff: { create: true },
            payload
          });
          proposed++;
        }
      } else if (mapRow.shopkit_product_id) {
        // Já mapeado -> compara e gera update se necessário
        // Podes otimizar guardando snapshot do último Shopkit
        const current = await fetch(`${process.env.SHOPKIT_API}/product/${mapRow.shopkit_product_id}`, {
          headers: { 'X-API-KEY': process.env.SHOPKIT_KEY }
        }).then(r => r.json());
        const diff = diffForUpdate(current, payload);
        if (Object.keys(diff).length) {
          await supa.from('change_queue').insert({
            supplier: 'natural_systems',
            item_code: supp.itemCode,
            action: 'update',
            diff,
            payload,
            target_id: mapRow.shopkit_product_id
          });
          proposed++;
        }
      }
    }

    res.json({ ok: true, proposed });
  } catch (e) {
    console.error(e);
    res.status(500).json({ ok: false, error: String(e.message || e) });
  }
});

// 2) Aprovar uma proposta (ID da queue) -> efetiva na Shopkit
app.post('/approve/:queueId', async (req, res) => {
  const { queueId } = req.params;
  const approver = req.body?.approved_by || 'admin';

  const { data: row, error } = await supa.from('change_queue').select('*').eq('id', queueId).single();
  if (error || !row) return res.status(404).json({ message: 'Proposal not found' });
  if (row.status !== 'pending') return res.status(400).json({ message: `Already ${row.status}` });

  try {
    let resp;
    if (row.action === 'create') {
      resp = await fetch(`${process.env.SHOPKIT_API}/product`, {
        method: 'POST',
        headers: {
          'X-API-KEY': process.env.SHOPKIT_KEY,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(row.payload)
      });
      if (!resp.ok) throw new Error(`Shopkit create failed: ${resp.status}`);
      const created = await resp.json();
      // cria mapeamento
      await supa.from('product_mappings').upsert({
        supplier: row.supplier,
        item_code: row.item_code,
        shopkit_product_id: created.id,
        shopkit_reference: row.payload.reference,
        status: 'mapped'
      });
    } else if (row.action === 'update') {
      if (!row.target_id) throw new Error('Missing target_id for update');
      resp = await fetch(`${process.env.SHOPKIT_API}/product/${row.target_id}`, {
        method: 'PUT',
        headers: {
          'X-API-KEY': process.env.SHOPKIT_KEY,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(row.payload)
      });
      if (!resp.ok) throw new Error(`Shopkit update failed: ${resp.status}`);
      await resp.json();
    } else if (row.action === 'delete') {
      // se quiseres suportar delete: usar DELETE /product/{id} se/quando disponível
      throw new Error('Delete not implemented to keep safety');
    }

    await supa.from('change_queue').update({
      status: 'done',
      approved_by: approver,
      approved_at: new Date().toISOString()
    }).eq('id', row.id);

    res.json({ ok: true });
  } catch (e) {
    await supa.from('change_queue').update({ status: 'failed' }).eq('id', row.id);
    res.status(500).json({ ok: false, error: String(e.message || e) });
  }
});

app.listen(3000, () => console.log('Sync API running on http://localhost:3000'));
