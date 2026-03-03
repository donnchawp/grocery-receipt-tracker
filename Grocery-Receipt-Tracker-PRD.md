# Product Requirements Document (PRD)

## Grocery Receipt Tracker PWA (WordPress-Based)

------------------------------------------------------------------------

## 1. Overview

The Grocery Receipt Tracker is a Progressive Web App (PWA) running on a
self-hosted WordPress site.\
Its purpose is to allow users to:

-   Capture grocery receipts using a mobile phone camera
-   Extract item names, prices, quantities, and discounts
-   Store structured receipt data in a WordPress-backed database
-   Track price changes over time
-   Compare prices by brand and by product category/class

The system must be privacy-first and fully self-hosted.

------------------------------------------------------------------------

## 2. Goals

### Primary Goal

Enable accurate tracking of grocery item prices over time using receipt
capture.

### Secondary Goals

-   Compare brand vs generic pricing trends
-   Detect inflation patterns
-   Visualise price changes
-   Support multi-user household usage

------------------------------------------------------------------------

## 3. Functional Requirements

### 3.1 Receipt Capture

Users must be able to: 1. Open the PWA 2. Capture a receipt photo 3.
Upload it to the server 4. Run OCR extraction 5. Review and edit parsed
results 6. Save structured receipt data

------------------------------------------------------------------------

### 3.2 Required Data Extraction

Each receipt must capture:

-   Store name
-   Date
-   Item name
-   Brand (if identifiable)
-   Category/class
-   Quantity
-   Original price
-   Discount amount (if present)
-   Final price paid

The system must store both original and discounted prices.

------------------------------------------------------------------------

### 3.3 Discount Handling

The system must support:

-   Line-item discounts
-   Multi-buy discounts (e.g., 2 for €5)
-   Basket-level discounts
-   Loyalty discounts

Discounts must be allocated proportionally to items where necessary.

------------------------------------------------------------------------

## 4. Data Model

### Entities

#### Receipt

-   id
-   store
-   date
-   total
-   image_url

#### Product

-   id
-   canonical_name
-   brand
-   category
-   barcode (optional)

#### Receipt Item

-   receipt_id
-   product_id
-   quantity
-   original_price
-   discount
-   final_price

#### Price History

-   product_id
-   date
-   store
-   final_price

------------------------------------------------------------------------

## 5. WordPress Architecture

The system will be implemented as a custom WordPress plugin.

### Backend

-   Custom database tables (recommended over post meta for performance)
-   REST API endpoints for PWA communication
-   Media library integration for receipt images
-   Background processing for OCR

### Security

-   Authenticated endpoints
-   Nonce validation
-   Role-based access

------------------------------------------------------------------------

## 6. PWA Requirements

-   Installable on mobile home screen
-   Offline caching of recent receipts
-   Background sync when online
-   Camera integration using getUserMedia
-   Receipt image compression before upload

------------------------------------------------------------------------

## 7. Analytics & Comparison

The system must support:

### Single Product Tracking

-   Price over time (line chart)
-   Lowest ever price
-   Highest ever price
-   Percentage change

### Brand Comparison

-   Compare multiple brands of same product class
-   Show average price difference

### Category Tracking

-   Average category price over time
-   Rolling averages

------------------------------------------------------------------------

## 8. Non-Functional Requirements

-   Fully self-hosted
-   GDPR compliant
-   Scalable to 10,000+ receipt items
-   Backup-friendly (compatible with filesystem + database backups)
-   Mobile-first design

------------------------------------------------------------------------

## 9. Acceptance Criteria

-   User can capture and save receipt successfully
-   OCR extracts items with minimum 85% accuracy before manual
    correction
-   Price history updates immediately after saving
-   Product comparison charts load under 2 seconds

------------------------------------------------------------------------

## 10. Future Enhancements

-   Barcode scanning
-   Shrinkflation detection (unit price change)
-   Price alerts
-   CSV export
-   Advanced AI anomaly detection

------------------------------------------------------------------------

End of PRD.
