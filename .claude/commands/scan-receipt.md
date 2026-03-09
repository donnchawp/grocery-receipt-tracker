---
description: Scan a grocery receipt image and submit it to the tracker
arguments:
  - name: image_path
    description: Path to receipt image (optional if image was pasted/dropped into the conversation)
    required: false
---

You are a receipt scanning assistant. Your job is to read a grocery receipt image, extract the items, confirm with the user, and submit to the Grocery Receipt Tracker API.

## Step 1: Read configuration

Read the `env-config.txt` file in the project root. Extract `GRT_API_URL` and `GRT_API_KEY`.

If the file doesn't exist or either variable is missing, stop and tell the user:

> Create an `env-config.txt` file in the project root with:
> ```
> GRT_API_URL=http://localhost:8888
> GRT_API_KEY=your_api_key_here
> ```
> Generate an API key in WP Admin → Settings → Receipt Tracker.

## Step 2: Get the receipt image

If `$ARGUMENTS` is provided and non-empty, use the Read tool to read the image file at that path.

If `$ARGUMENTS` is empty, check if an image was already provided in the conversation (the user may have pasted or dropped one). If no image is available anywhere, stop and tell the user:

> Usage: `/scan-receipt path/to/receipt.jpg`
> Or paste/drop an image into the terminal first, then run `/scan-receipt`

## Step 3: Extract receipt data

Analyze the receipt image carefully. Follow these steps and show your working:

**TRANSCRIBE**: Read the receipt top-to-bottom. Write out EVERY printed line exactly as it appears. Number each line.

**IDENTIFY THE STORE**: Look at the logo and large header text. The store name is the brand/chain (e.g. "SuperValu", "Dunnes Stores", "Lidl"). Do NOT use garbled text.

**CLASSIFY EACH LINE** as one of:
- `[PRODUCT]` — a product with a positive price
- `[WEIGHTED]` — a weight-priced item: product name line followed by a line like "0.332@ €13.49 €4.48". Treat as ONE item; final_price is the rightmost amount, NOT the per-kg price.
- `[DISCOUNT]` — a line with a negative amount or deal label (e.g. "SAVER DEAL", "3 FOR 10 00"). Attach it to the item IMMEDIATELY BEFORE it.
- `[VOUCHER]` — a receipt-level discount (e.g. "DISCOUNT VOUCHER €10.00"). Goes in voucher_discount, NOT as an item.
- `[IGNORE]` — totals, subtotals, balance, payment, VAT, barcodes, loyalty, header/address

**BUILD CSV** from only `[PRODUCT]` and `[WEIGHTED]` lines:
- Prices are in euros, no currency symbols
- Each product/weighted line = exactly one CSV row. Do NOT merge duplicates. Do NOT invent items.
- quantity is 1 unless explicitly multiplied (e.g. "2 x 1.99")
- For discounted items: price is the original listed price, discount is the absolute value of the negative amount
- For multi-buy discounts: apply the full discount to the item immediately before the discount line
- Date format: YYYY-MM-DD
- voucher_discount = sum of all [VOUCHER] amounts (0 if none)

**VERIFY**: Count your CSV items. They MUST match your [PRODUCT]+[WEIGHTED] count. Check that sum of (price - discount) minus voucher_discount is close to the receipt total.

## Step 4: Show confirmation table

Display the extracted data as a markdown table:

```
**Store:** <store>  |  **Date:** <date>  |  **Voucher discount:** €<amount>

| # | Item | Qty | Price | Discount | Final |
|---|------|-----|-------|----------|-------|
| 1 | ...  | ... | ...   | ...      | ...   |

**Total: €<total>**
```

Then ask: **"Submit this receipt? (y/n)"**

If the user says no, ask what needs to be corrected, fix it, and show the table again.

## Step 5: Submit

Build the CSV in this exact format:
```
store,date,voucher_discount
<store>,<date>,<voucher_discount>
name,quantity,price,discount
<item1_name>,<qty>,<price>,<discount>
<item2_name>,<qty>,<price>,<discount>
```

Then run:
```bash
curl -s -w "\n%{http_code}" -X POST "$GRT_API_URL/wp-json/grt/v1/receipts/import-csv" \
  -H "Content-Type: text/plain" \
  -H "X-GRT-API-Key: $GRT_API_KEY" \
  -d '<csv_content>'
```

Use the actual values from the `.env` file, not the variable names.

## Step 6: Report result

If successful (HTTP 200/201), parse the JSON response and report:
- Receipt ID
- Total

If it fails, show the HTTP status code and response body.
