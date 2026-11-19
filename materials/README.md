# SGIR Materials — Warehouse Inventory

A separate inventory system for the Materials Man / Warehouse Manager, served from `/materials`.
It has its own logins, roles, session cookie and tables (`mat_*`). It does not use the Feedback dashboard's
users. It only shares the `.env` database settings.

## First run
1. Upload the `materials/` folder with the rest of the site. No SQL import is needed.
2. Open `https://your-site/materials/`. On first load the tables are created and the
   **SGIR Item Master** (`data/item_master.csv`, 1,369 items) is loaded as the opening stock.
3. You'll be sent to **first-run.php** to create the first administrator. This page locks itself
   as soon as one account exists, so do this right after deploying.
4. Go to **Users & Access** and add warehouse managers, toolpushers and other staff.

## Access levels
| Role | Can do |
|---|---|
| Administrator | Everything, plus users, categories, Excel import and the activity log |
| Warehouse Manager | Receive and issue stock, stock-count corrections, create and edit items |
| View Only | See stock levels, item history, vouchers and exports (for toolpushers, rig managers and others) |

## Daily use
- **Receive Stock** records how the stock arrived (purchase order delivery, transfer from another rig, return from crew, found in a count) along with the supplier and PO/waybill number.
- **Issue Stock** records how the stock left the store (issued to crew, transfer out, returned to supplier, damaged, lost), plus **who collected it**, the department, who authorized it and the requisition number.
- One slip can hold several items. Every slip gets a number (`RV-…` for receipts, `IS-…` for issues, `SC-…` for stock counts) and a printable voucher with signature lines.
- Stock can never go negative. If any line on a slip would do that, nothing on the slip is saved.
- Set a **re-order level** on an item to see it under "Needs re-ordering" on the dashboard.
- Both the Inventory list and Movement History export to CSV, which opens in Excel.

## Updating from Excel
**Admin → Import Excel** accepts the same layout as the Item Master (.xlsx or .csv):
`Item Name | Description | Unit | Quantity`. You can choose to add new items only, or to
replace quantities after a physical count (each change is logged as a correction).
