# Promotions & Loyalty

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Engine promo dan loyalty untuk meningkatkan penjualan dan retensi pelanggan.

## Fitur Saat Ini

### Pricing Rules (Promo Engine)
- **Standard Discount** — diskon persentase/nominal per produk atau kategori
- **Qty Break** — harga khusus untuk pembelian dalam jumlah tertentu (tiered pricing)
- **Bundle Price** — harga spesial untuk paket produk
- **Buy X Get Y** — beli produk tertentu, dapat produk lain dengan harga khusus
- **Customer Scope** — promo bisa dibatasi untuk: semua, walk-in, registered, member, atau tier tertentu
- **Schedule** — promo bisa dijadwalkan dengan start/end date
- **Preview** — lihat dampak promo sebelum checkout

### Customer Vouchers
- Voucher per customer dengan kode unik
- Minimum order, periode berlaku
- Voucher bisa di-redeem di checkout

### Loyalty Program
- **Tiers**: Regular → Silver → Gold → Platinum
- **Poin**: earn point per transaksi, redeem untuk diskon
- **Auto tier sync**: tier otomatis naik berdasarkan total belanja
- **Settings**: enable/disable earn & redeem, rate amount, point value

### Multi-Price List
- Harga khusus per kelompok pelanggan (all, walk-in, registered, member)
- Prioritas: price list dengan prioritas tertinggi yang cocok akan dipakai
- Harga per produk dalam price list

## Database

- `pricing_rules` + `pricing_rule_qty_breaks` + `pricing_rule_bundle_items` + `pricing_rule_buy_get_items`
- `customer_vouchers` (per customer)
- `loyalty_point_histories` (earn/redeem trail)
- `price_lists` + `price_list_items`

## Halaman dan Route

| Route | Modul |
|-------|-------|
| `pricing-rules.index` | Pricing Rules (CRUD) |
| `pricing-rules.preview` | Preview promo |
| `customer-vouchers.*` | Voucher customer |
| `price-lists.index` | Price List (settings) |
| `price-lists.show` | Detail price list + edit harga |
| `settings.loyalty` | Loyalty settings |

## Alur Pricing di Checkout

1. Cart items → PricingService mengevaluasi semua active rules
2. Rules diurutkan by priority → dicocokkan dengan customer scope
3. Diskon dialokasikan per item → subtotal after promo
4. Voucher dicek → loyalitas poin redeem dihitung
5. PPN ditambahkan → grand total final

## Catatan

- Pricing rules bisa tumpang tindih — rule dengan priority lebih tinggi diutamakan
- Qty break: tier price berdasarkan quantity pembelian produk tertentu
- Bundle tetap: harga spesial untuk komposisi dan jumlah per produk yang sudah ditentukan.
- **Bundle Mix & Match:** pilih **Bundle Price → Bebas pilih (Mix & Match)**, isi jumlah porsi dan harga paket, lalu pilih produk yang boleh masuk paket. Kuantitas dijumlahkan lintas produk, termasuk beberapa porsi dari produk yang sama.
  - Untuk 2 porsi Rp75.000 dan 3 porsi Rp109.000, buat dua rule dengan daftar produk yang sama. Beri paket 3 prioritas lebih tinggi agar dipakai dulu. Contoh: 5 porsi menjadi paket 3 + paket 2 (Rp184.000).
  - Sistem memilih porsi dengan harga dasar tertinggi terlebih dahulu untuk mengisi masing-masing paket. Unit yang tersisa tetap bisa mengikuti aturan promo lain. Bundle tidak diterapkan jika harga paket tidak lebih murah dari harga dasar porsi yang dipilih.
- Buy X Get Y: buy item A → dapat diskon untuk item B
