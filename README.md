# Shopify Made Easy – Orders Confirmation CRM

A **multi-tenant Shopify order management CRM** built with PHP, MySQL, and vanilla CSS. This system allows users to import Shopify orders and products via CSV, manage order statuses (confirmed/follow-up), integrate with FIABILO shipping, and analyze sales data through an interactive dashboard.

---

## 🎨 Theme & Design

- **Light mode design**: Background `#EEEEEE`, Text `#000000`, Accent `#253900`, Buttons `#08CB00`
- **Responsive layout**: Collapsible sidebar navigation with mobile hamburger menu
- **Clean UI**: Card-based components, status badges, and interactive tables

---

## 📁 Project Structure

```
/me
├── index.php              # Main entry point (delegates to public/index.php)
├── bootstrap.php          # Core config loader, PDO setup, session init
├── composer.json          # Composer config
├── .htaccess              # Apache rewrite rules
│
├── config/
│   ├── app.php            # App config (encryption key)
│   └── database.php       # MySQL connection credentials
│
├── layouts/
│   └── layout.php         # Main template with sidebar navigation
│
├── pages/
│   ├── dashboard.php      # Analytics dashboard with charts
│   ├── orders.php         # Order management (upload, list, view, edit)
│   ├── products.php       # Product management (import, list, view)
│   ├── shops.php          # Shop management (create, list, view)
│   ├── cart.php           # Order aggregation & cost analysis
│   ├── integration.php    # FIABILO integration settings
│   ├── ready.php          # Shipped orders tracking
│   └── fiabilo.php        # Alternative FIABILO config page
│
├── public/
│   ├── index.php          # Router for auth & page routing
│   ├── login.php          # Login form
│   ├── register.php       # Registration form
│   ├── install.php        # Database installer
│   └── assets/
│       └── style.css      # Main stylesheet (24KB)
│
├── src/
│   ├── CsvParser.php      # Generic CSV parser (RFC 4180)
│   ├── ProductImport.php  # Shopify products CSV parser
│   ├── OrderImport.php    # Shopify orders CSV parser
│   ├── OrderProductMatch.php  # Line item to product matching
│   └── FiabiloHelper.php  # FIABILO API client & encryption
│
├── sql/
│   ├── schema.sql         # Main database schema (6 tables)
│   ├── add_cost_column.sql    # Migration: cost column
│   ├── add_fiabilo_orders.sql # Migration: fiabilo columns
│   └── add_user_integrations.sql  # Migration: integrations table
│
└── assets/
    └── style.css          # Alternative CSS location
```

---

## 🗄️ Database Schema (6 Tables)

### 1. `users`
| Column | Type | Description |
|--------|------|-------------|
| `id` | INT (PK) | Auto-increment user ID |
| `email` | VARCHAR(255) | Unique email address |
| `password_hash` | VARCHAR(255) | bcrypt password hash |
| `name` | VARCHAR(255) | Display name (optional) |
| `created_at` | DATETIME | Registration timestamp |

### 2. `shops`
| Column | Type | Description |
|--------|------|-------------|
| `id` | INT (PK) | Shop ID |
| `user_id` | INT (FK) | Owner user ID |
| `name` | VARCHAR(255) | Shop name |
| `created_at` | DATETIME | Creation timestamp |

### 3. `products`
| Column | Type | Description |
|--------|------|-------------|
| `id` | INT (PK) | Product ID |
| `shop_id` | INT (FK) | Parent shop ID |
| `handle` | VARCHAR(255) | Shopify product handle |
| `title` | VARCHAR(500) | Product title |
| `body_html` | TEXT | Product description HTML |
| `vendor` | VARCHAR(255) | Vendor name |
| `type` | VARCHAR(255) | Product type |
| `variant_sku` | VARCHAR(255) | SKU for matching |
| `variant_price` | DECIMAL(12,2) | Selling price |
| `cost` | DECIMAL(12,2) | Cost per item (for margin) |
| `image_src` | VARCHAR(1000) | Image URL |
| `status` | VARCHAR(50) | active/draft/archived |
| `created_at` | DATETIME | Import timestamp |

### 4. `orders`
| Column | Type | Description |
|--------|------|-------------|
| `id` | INT (PK) | Order ID |
| `shop_id` | INT (FK) | Parent shop ID |
| `name` | VARCHAR(100) | Order # (e.g., #1081) |
| `order_created_at` | DATETIME | Shopify order date |
| `financial_status` | VARCHAR(50) | paid/pending/refunded |
| `fulfillment_status` | VARCHAR(50) | fulfilled/unfulfilled |
| `total` | DECIMAL(12,2) | Order total |
| `currency` | VARCHAR(10) | TND, USD, EUR, etc. |
| `shipping_method` | VARCHAR(255) | Shipping method |
| `billing_name` | VARCHAR(255) | Customer name |
| `billing_phone` | VARCHAR(100) | Customer phone |
| `billing_address` | VARCHAR(500) | Billing street |
| `billing_city` | VARCHAR(255) | Billing city |
| `billing_zip` | VARCHAR(50) | Billing postal code |
| `billing_country` | VARCHAR(100) | Billing country |
| `shipping_name` | VARCHAR(255) | Shipping recipient |
| `shipping_address` | VARCHAR(500) | Shipping street |
| `shipping_city` | VARCHAR(255) | Shipping city |
| `shipping_zip` | VARCHAR(50) | Shipping postal code |
| `notes` | TEXT | Order notes |
| `phone` | VARCHAR(100) | Alternative phone |
| `confirmed` | TINYINT(1) | User confirmed by phone |
| `follow_up` | TINYINT(1) | Needs follow-up |
| `fiabilo_tracking_code` | VARCHAR(100) | FIABILO tracking code |
| `fiabilo_status` | VARCHAR(50) | Delivery status |
| `created_at` | DATETIME | Import timestamp |

### 5. `order_line_items`
| Column | Type | Description |
|--------|------|-------------|
| `id` | INT (PK) | Line item ID |
| `order_id` | INT (FK) | Parent order ID |
| `lineitem_name` | VARCHAR(500) | Product name |
| `lineitem_sku` | VARCHAR(255) | SKU |
| `lineitem_price` | DECIMAL(12,2) | Unit price |
| `lineitem_quantity` | INT | Quantity ordered |
| `vendor` | VARCHAR(255) | Vendor name |
| `fulfillment_status` | VARCHAR(50) | Line fulfillment status |
| `product_id` | INT (FK, nullable) | Matched product ID |

### 6. `user_integrations`
| Column | Type | Description |
|--------|------|-------------|
| `id` | INT (PK) | Integration ID |
| `user_id` | INT (FK) | User ID |
| `provider` | VARCHAR(50) | 'fiabilo' |
| `add_token_encrypted` | TEXT | AES-256-CBC encrypted token |
| `tracking_token_encrypted` | TEXT | AES-256-CBC encrypted token |
| `updated_at` | DATETIME | Last update timestamp |

---

## 🔐 Authentication System

### Entry Points
- **`/public/index.php`** or **`/index.php`** - Main router
- Unauthenticated users → Login/Register pages
- Authenticated users → Dashboard + sidebar navigation

### Flow
1. **Register**: Email, optional name, password (min 6 chars) → bcrypt hash → auto-login
2. **Login**: Email + password → session (`user_id`, `user_name`)
3. **Logout**: `?page=logout` → clears session → redirects to login

### Multi-Tenancy
- Every user has isolated data (shops, orders, products, integrations)
- All queries filter by `user_id` via shop relationship
- No admin role - each account is a CRM owner

---

## 📊 Pages & Features

### 1. Dashboard (`?page=dashboard`)
**Purpose**: Analytics overview with KPIs and charts

**Components**:
| Element | Behavior |
|---------|----------|
| **Date Range Dropdown** | Filter: Last 7/30/90/365 days |
| **Total Sales KPI** | Sum of order totals for period, % change vs previous |
| **Orders KPI** | Count of orders for period, % change vs previous |
| **Confirmation Rate KPI** | Confirmed ÷ Total orders × 100 |
| **Products KPI** | Total products across all shops |
| **Sales Chart** | Line chart with current vs previous period comparison |
| **Order Status Donut** | Confirmed / Follow-up / Shipped / Pending breakdown |
| **Recent Orders Table** | Last 5 orders with status badges, link to view |
| **Top Products List** | Top 5 products by order count |
| **Sales by Shop** | Top 5 shops with sales amounts and percentages |

**Buttons**:
- `View all` links → navigate to respective list pages
- Order links → `?page=order-view&id=X`

---

### 2. Orders Module (`pages/orders.php`)

#### 2.1 Order Upload (`?page=order-upload`)
**Purpose**: Import Shopify orders from CSV

**Components**:
| Element | Behavior |
|---------|----------|
| **Shop Dropdown** | Select target shop for import |
| **Dropzone** | Drag & drop or click to browse CSV |
| **File Preview** | Shows filename, size, remove button |
| **Browse Files Button** | Opens file picker |
| **Upload Orders Button** | Submits CSV (disabled until file selected) |
| **Progress Overlay** | Shows animated progress bar during upload |
| **Missing Info Filter** | Lists orders with missing billing name/phone/address |
| **Fix Now Button** | Opens sidebar to fix order details |
| **Fix Sidebar** | Form: order name (readonly), billing name, phone, address |

**Processing Logic**:
1. Parse CSV using `OrderImport::parse()`
2. Skip duplicates (same `shop_id` + `name`)
3. Insert order with all billing/shipping fields
4. For each line item, attempt product match by title/SKU
5. Show import count + skip count

---

#### 2.2 All Orders (`?page=orders`)
**Purpose**: View all imported orders

**Components**:
| Element | Behavior |
|---------|----------|
| **Shop Filter Dropdown** | Filter by specific shop |
| **Orders Table** | Order #, Shop, Date, Total, Status, View button |
| **Status Badges** | Green "Confirmed" / Yellow "Follow up" / Gray "—" |
| **View Button** | Opens order detail page |
| **Pagination** | 10 per page, Previous/Next + page numbers |

---

#### 2.3 Confirmed Orders (`?page=orders-confirmed`)
**Purpose**: View and manage confirmed orders + send to shipping

**Additional Components**:
| Element | Behavior |
|---------|----------|
| **Bulk Select Checkboxes** | Select orders without tracking code |
| **Select All Checkbox** | Toggle all order checkboxes |
| **Shipping Dropdown** | Choose "FIABILO" |
| **Send Order(s) Button** | Bulk send selected orders to FIABILO |
| **Shipping Column** | Shows truck icon + tracking code for sent orders |
| **Settings Button** | Blue icon linking to order view |

**FIABILO Send Flow**:
1. Validate user has saved integration tokens
2. Decrypt add token using AES-256-CBC
3. For each order, call `FiabiloHelper::sendOrder()`
4. Save tracking code and "En attente" status
5. Display success/error summary

---

#### 2.4 Follow Up (`?page=orders-followup`)
**Purpose**: View orders flagged for follow-up

Same layout as All Orders, filtered by `follow_up = 1`.

---

#### 2.5 Order View (`?page=order-view&id=X`)
**Purpose**: View and edit single order details

**Components**:
| Element | Behavior |
|---------|----------|
| **Order Header Card** | Shop, date, billing info, shipping info, notes |
| **Total Price Input** | Inline editable, auto-saves on change |
| **Edit Customer Info Button** | Switches to edit mode |
| **Mark Confirmed Button** | Sets `confirmed = 1`, redirects back |
| **Flag Follow-up Button** | Sets `follow_up = 1`, redirects back |
| **Line Items Card** | Product images, names, quantities, prices |
| **In Catalog Badge** | Green badge if product matched |
| **Modify Order Products Button** | Opens product selection modal |
| **Unsaved Changes Snackbar** | Shows Save/Discard when changes detected |

**Edit Mode**:
- Form fields: billing name, phone, address, city, zip
- Shipping address, city, zip
- Total price
- Save/Cancel buttons

**Product Modal**:
- Left panel: Current products with checkboxes to remove
- Right panel: All shop products with checkboxes to add
- Search filter
- Prices auto-recalculate based on selections

---

### 3. Products Module (`pages/products.php`)

#### 3.1 Import Products (`?page=product-import`)
**Purpose**: Import Shopify products from CSV

**Components**:
| Element | Behavior |
|---------|----------|
| **Shop Dropdown** | Select target shop |
| **Dropzone** | Drag & drop CSV file |
| **Import Button** | Process CSV upload |
| **Missing Info Filter** | Products missing photo/price/cost |
| **Fix Now Button** | Opens sidebar to update product |
| **Fix Sidebar** | Form: title (readonly), photo URL, price, cost |

**Duplicate Handling**: Skip products with existing handle in shop.

---

#### 3.2 All Products (`?page=products`)
**Purpose**: Browse all products across shops

**Components**:
| Element | Behavior |
|---------|----------|
| **Shop Filter Dropdown** | Filter by shop |
| **Products Table** | Image, Title, Shop, Price, View link |
| **Pagination** | 10 per page |

---

#### 3.3 Product View (`?page=product-view&id=X`)
**Purpose**: View product details and set cost

**Components**:
| Element | Behavior |
|---------|----------|
| **Product Image** | Large preview |
| **Price Display** | Read-only selling price |
| **Vendor Display** | Product vendor |
| **Cost Input** | Editable cost per item |
| **Update Cost Button** | Saves cost for margin calculations |

---

### 4. My Shops (`?page=shops`)
**Purpose**: Manage shops (containers for products/orders)

**Components**:
| Element | Behavior |
|---------|----------|
| **Create a Shop Button** | Opens shop creation form |
| **Shops Table** | Shop name, created date, Open button |
| **Open Button** | Views shop details with product list |

#### Shop Create (`?page=shop-create`)
- Form: Shop name input
- Create Shop / Cancel buttons

#### Shop View (`?page=shop-view&id=X`)
- Shop name heading
- Import Products CSV button (links to product-import with shop preselected)
- Products table for this shop

---

### 5. Cart (`?page=cart`)
**Purpose**: Aggregate multiple orders for fulfillment planning

**Layout**: Two-column with order list (left) and analysis panel (right)

**Left Column**:
| Element | Behavior |
|---------|----------|
| **Search Orders Input** | Filter by order # or customer name |
| **Order Checkboxes** | Multi-select orders |
| **Pagination** | 10 orders per page |

**Right Column Buttons**:
| Button | View |
|--------|------|
| **Products Needed** | Aggregated product quantities |
| **Total Sales** | Sum of selected order totals |
| **Cost** | Facture with product costs and line totals |

**Products Needed View**:
- List of products with images and total quantities
- Paginated (10 per page)

**Total Sales View**:
- Single total amount
- Currency display
- Order count

**Cost View**:
- Table: Product, Qty, Unit Cost, Line Total
- Grand total at bottom
- Instructions to set product costs

---

### 6. Integration (`?page=integration`)
**Purpose**: Connect external shipping services

**FIABILO Card**:
| State | Display |
|-------|---------|
| **Not Connected** | Token input form |
| **Connected** | Green "Connected" badge, Modify tokens button |

**Form Fields**:
| Field | Description |
|-------|-------------|
| **Add Token** | For creating shipments (password field) |
| **Tracking Token** | For checking delivery status (password field) |
| **Save Tokens Button** | Encrypts and stores tokens |
| **Test Connection Button** | Validates tokens with FIABILO API |

**Security**: Tokens encrypted with AES-256-CBC before database storage.

---

### 7. Ready (`?page=ready`)
**Purpose**: Track orders sent to shipping company

**Components**:
| Element | Behavior |
|---------|----------|
| **Shop Filter Dropdown** | Filter by shop |
| **Orders Table** | Order #, Shop, Date, Total, Tracking Code, Status, View |
| **Status Column** | FIABILO delivery status (En attente, Livré, etc.) |
| **Pagination** | 10 per page |

Only shows orders where `fiabilo_tracking_code IS NOT NULL`.

---

## 🔌 FIABILO Integration

### API Endpoints
- Base URL: `https://www.fiabilo.tn/api/v1/post.php`
- Protocol: POST with form-urlencoded data

### Send Order (Add API)
```php
$body = [
    'token' => $addToken,
    'prix' => $order['total'],
    'nom' => $order['billing_name'],
    'tel' => $order['billing_phone'],
    'adresse' => $order['billing_address'],
    'gouvernerat' => $order['billing_country'],
    'ville' => $order['billing_city'],
    'cp' => $order['billing_zip'],
    'designation' => $order['designation'],  // "Product A (x2), Product B (x1)"
    'nb_article' => $order['nb_article'],
    'msg' => $order['notes'],
    'ouvrir' => 1,
];
```

### Get Status (Tracking API)
```php
$body = ['token' => $trackingToken, 'code' => $trackingCode];
// Response: ['status' => 1, 'etat' => 'En attente|Livré|...']
```

### Token Encryption
- Algorithm: AES-256-CBC
- IV: 16 random bytes prepended to ciphertext
- Storage: Base64 encoded (IV + ciphertext)
- Key: SHA-256 hash of app encryption key

---

## 🛠️ Source Helpers

### CsvParser
Generic CSV reader supporting RFC 4180 (quoted newlines).
```php
$rows = CsvParser::read($filePath);
// Returns array of associative arrays keyed by header names
```

### ProductImport
Parses Shopify products CSV.
- Groups rows by Handle (product ID)
- Extracts first image by position
- Returns array of product data

### OrderImport
Parses Shopify orders CSV.
- Groups rows by Name (order #)
- Collects line items for each order
- Parses dates and decimal values

### OrderProductMatch
Matches order line items to catalog products.
- Builds index: normalized title → product ID, SKU → product ID
- Returns matched product_id for linking

### FiabiloHelper
Complete FIABILO API client with:
- `encrypt()` / `decrypt()` – AES-256-CBC token encryption
- `sendOrder()` – Create shipment, returns tracking code
- `getStatus()` – Check delivery status
- `testConnection()` – Validate tokens

---

## 🚀 Setup Instructions

### 1. Database
Run the SQL schema on your MySQL server:
```sql
-- Host: srv1454.hstgr.io
-- Database: u755103422_gloras
-- Run: sql/schema.sql
```

### 2. Configuration
Edit `config/database.php` with your credentials:
```php
return [
    'host'     => 'your-host',
    'port'     => 3306,
    'dbname'   => 'your-database',
    'username' => 'your-username',
    'password' => 'your-password',
    'charset'  => 'utf8mb4',
];
```

### 3. Web Server
Point document root to `public/` folder, or access via:
```
http://localhost/me/public/
```

Or use root `index.php` which delegates:
```
http://localhost/me/
```

### 4. Usage Flow
1. **Register** an account
2. **Create a Shop** (My Shops → Create)
3. **Import Products** (Shopify products CSV)
4. **Import Orders** (Shopify orders CSV)
5. **Confirm Orders** (View order → Mark confirmed)
6. **Connect FIABILO** (Integration → Save tokens)
7. **Send to Shipping** (Confirmed orders → Select → Send)
8. **Track Delivery** (Ready → View shipping status)

---

## 📋 Button Behaviors Summary

| Page | Button | Action |
|------|--------|--------|
| Dashboard | Date dropdown | Reloads with new date range |
| Dashboard | View all | Navigates to respective list page |
| Order Upload | Browse files | Opens file picker |
| Order Upload | Upload orders | Submits CSV, imports orders |
| Order Upload | Fix now | Opens sidebar to edit order |
| Orders List | Shop dropdown | Filters table by shop |
| Orders List | View | Opens order detail page |
| Confirmed | Select checkboxes | Selects orders for bulk action |
| Confirmed | Send order(s) | Sends selected to FIABILO |
| Order View | Edit customer info | Switches to edit mode |
| Order View | Mark confirmed | Sets confirmed flag |
| Order View | Flag follow-up | Sets follow-up flag |
| Order View | Modify products | Opens product selection modal |
| Product Import | Import | Processes CSV upload |
| Product View | Update cost | Saves cost per item |
| Shops | Create a shop | Opens creation form |
| Shops | Open | Views shop details |
| Cart | Products needed | Shows aggregated quantities |
| Cart | Total sales | Shows sales sum |
| Cart | Cost | Shows cost breakdown |
| Integration | Save tokens | Encrypts and stores tokens |
| Integration | Test connection | Validates API connection |
| Integration | Modify tokens | Shows token edit form |

---

## ⚠️ Important Notes

1. **No Shopify API**: Everything is CSV-based. Export orders/products from Shopify Admin.
2. **Duplicate Prevention**: Orders with same name in shop are skipped during import.
3. **Data Isolation**: Each user sees only their own shops, orders, and products.
4. **Token Security**: FIABILO tokens are AES-256 encrypted, never stored in plaintext.
5. **Cost Field**: Used for margin calculations in Cart → Cost view.
6. **Status Updates**: FIABILO status must be manually refreshed (no webhook).

---

## 📞 Support

- **Database Issues**: Check `config/database.php` credentials
- **Import Errors**: Ensure CSV matches Shopify export format
- **FIABILO Errors**: Verify tokens from your FIABILO Expéditeur account
- **Missing Products**: Match is by title or SKU - ensure products imported first
