# Everymarket Module for FreeScout — CS Agent Guide

## Overview

The Everymarket module integrates your Everymarket store directly into FreeScout's conversation sidebar. When a customer emails in, the module automatically pulls their order history, order details, inventory status, shipment tracking, and CS request history — all without leaving the helpdesk.

This guide walks through the typical CS workflow from opening a conversation to resolving the customer's issue.

---

## 1. Opening a Conversation

When you open a customer conversation, the right sidebar shows the **Everymarket panel** with two collapsible sections:

- **Customer's Order History** — all orders associated with the customer's email
- **Order Details** — detailed view of the specific order tied to this conversation

### What Happens Automatically

1. The module looks up the customer's email in the Everymarket store and fetches all completed orders.
2. If the customer has **exactly one order**, the module automatically:
   - Sets the **Order Number** custom field to that order number
   - Loads the full Order Details panel
3. If the customer has **multiple orders**, the Order History list displays all of them. You select the relevant order manually.

### Manual Refresh

- Click the **Refresh** icon under "Customer's Order History" to re-fetch orders from the API.
- Click the **Refresh** icon under "Order Details" to reload details based on the current Order Number custom field value.

---

## 2. Customer's Order History

The Order History section shows a list of the customer's orders, each displaying:

| Field | Description |
|-------|-------------|
| **Order Number** | e.g., `#EM20-6903057` — click to open the detail panel |
| **Total** | Order total with currency |
| **Date** | When the order was placed |
| **Payment Status** | Paid, Pending, Refunded, Partially Refunded |
| **CS Badge** | A blue "CS" label appears if there are active CS requests on the order |

### Actions

- **Click an order** to open the slide-out Order Detail panel from the right side of the screen.
- **Click the copy icon** next to the order number to copy it to your clipboard (useful for pasting into emails or the Everymarket dashboard).

### Search Customer

If no orders are found (e.g., the customer used a different email), click **Search Customer** to search by order number or email. When you find the right customer, click their email to link it to the conversation.

---

## 3. Order Details Section

The **Order Details** section in the sidebar (below Order History) shows a detailed view for the order linked to the conversation via the **Order Number** custom field. Click any order in the Order History list to view its details as well.

The Order Details panel contains the following sections:

### 3.1 CS Requests

See [Section 4: CS Requests](#4-cs-requests) below for full details.

### 3.2 Items

Shows each product in the order with:

- **Product image** and **name** (linked to the store page)
- **SKU**
- **EC SKU** — the warehouse SKU from Eccang
- **Onway Qty** — how many units are currently in transit to the warehouse (in green)
- **Sellable Qty** — how many units are currently available to ship
- **Price** and **Quantity** ordered

> **Tip**: If a customer asks "when will my item be back in stock?", check the Onway Qty and the Inbound Shipments section for the ETA.

### 3.3 Shipments

Shows per-item fulfillment details, mirroring what the operations team sees on the Everymarket dashboard (`/dashboard/order_items`). For each order item:

- **Status** badge (e.g., Shipped, Pending, Canceled)
- **Carrier + Tracking Number** — the main tracking provided to the customer
- **International Tracking** — shown when the order uses international forwarding
- **Shipping Method** — the selected shipping service

Below the main tracking, you'll see source-specific shipment cards:

| Card | Color | What It Shows |
|------|-------|---------------|
| **Eccang Order** | Green bar | Warehouse order code, fulfillment status, tracking number, SKUs and quantities shipped |
| **Shipstation** | Blue bar | Shipstation order number and tracking number |
| **STC Fulfill / Fulfill Order** | Orange bar | Direct purchase carrier + tracking, forwarding carrier + tracking, purchase order number |

> **When to use**: Customer asks "where is my package?" — check the Shipments section for the latest tracking number and carrier. If there's an Eccang order, you can see if the warehouse has dispatched it.

### 3.4 Inbound Shipments (ASN)

Shows inbound warehouse shipments (Advanced Shipping Notices) for products in this order:

- **Carrier** and **receiving status**
- **Tracking number** and **EC SKU**
- **Quantity sent** vs. **Quantity received**
- **ETA date**

> **When to use**: Customer asks about restocking or back-order timelines — this shows what's on the way to the warehouse and when it's expected.

### 3.5 Order Summary

Quick summary:

- **Order Status** — fulfillment state (Fulfilled, Partially Fulfilled, Unfulfilled)
- **Order Placed** — date
- **Payment Status** — Paid, Pending, etc.
- **View on EM** — link to the order in the Everymarket Customer Service dashboard
- **Download Invoice** — PDF invoice link

### 3.6 Shipping Address

The customer's delivery address.

### 3.7 Tracking

Shipment tracking from the store platform. Shows:

- Shipment number, carrier, and status badge
- Shipping rate name and cost
- Clickable tracking number (links to carrier tracking page)

### 3.8 Receipt

Financial summary:

- Subtotal (with item count)
- Adjustments / Discounts
- Shipping cost
- Tax
- **Total**
- **Paid by customer**

### How the Order Number Gets Set

1. **Automatically** — if the customer has exactly one order
2. **Manually** — set the "Order Number" custom field in the conversation, then click Refresh

> **Important**: The auto-set only fills in the Order Number if the field is currently empty. It never overwrites a manually-entered value.

---

## 4. CS Requests

CS Requests are the communication bridge between the CS team (FreeScout) and the operations team (Everymarket dashboard). They appear at the top of the Order Details section.

### 4.1 Viewing Existing CS Requests

Each CS request shows:

- **Product** — image, name, SKU, price, quantity
- **Reason** — Cancel, Refund, Return, Tracking Info, Others
- **Created by** — role and email of whoever created it
- **Event timeline** — chronological list of notes and actions taken

### 4.2 Creating a New CS Request

If no CS requests exist for the order, a form appears:

1. **Select the Order Item** from the dropdown
2. **Select the Reason** — Cancel, Refund, Return, Tracking Info, Others
3. **Write a Note** explaining the situation
4. Click **Submit Request**

The request is immediately created in the Everymarket system and the conversation's "CS Request Status" custom field is set to `waiting_reply`.

### 4.3 Adding Notes to an Existing CS Request

For open CS requests, you can:

1. Type a note in the **Add Note** textarea
2. Click **Add Note** to append it to the request's timeline

### 4.4 Closing a CS Request

Click **Close Request** on an open CS request to finalize it. The conversation's "CS Request Status" custom field updates to `request_closed`.

---

## 5. Typical CS Workflows

### Workflow A: "Where is my order?"

1. Open the conversation. Order History loads automatically.
2. If only one order exists, Order Details loads automatically. Otherwise, click the relevant order or set the Order Number field.
3. Check the **Shipments** section for the carrier and tracking number.
4. If there's an Eccang order with tracking, share the tracking number with the customer.
5. If tracking shows "unavailable", check if a CS request is needed to escalate.

### Workflow B: "I want to cancel my order"

1. Open Order Details for the relevant order.
2. Check the **Shipments** section — if status is already "Shipped", inform the customer.
3. If not yet shipped, scroll to **CS Requests** and create a new request:
   - Item: select the product
   - Reason: **Cancel**
   - Note: explain the customer's cancellation request
4. Click Submit. The operations team will see this on their dashboard.

### Workflow C: "I want a refund"

1. Open Order Details and review the order.
2. Create a CS Request with Reason: **Refund**.
3. Include details in the note (reason for refund, partial/full, etc.).
4. Monitor the CS Request timeline for the operations team's response.

### Workflow D: "When will this item be back in stock?"

1. Open Order Details for the customer's order.
2. Check the **Items** section for **Onway Qty** — this shows how many units are in transit to the warehouse.
3. Check the **Inbound Shipments (ASN)** section for the **ETA date** and receiving status.
4. Share the estimated restock timeline with the customer.

### Workflow E: Customer used a different email

1. The Order History shows "No orders found."
2. Click **Search Customer**.
3. Search by the customer's order number (e.g., `EM20-1234567`) or their other email.
4. Click the matching customer email to link it to the current conversation.
5. The page reloads and orders now appear.

---

## 6. Tags and Folders

FreeScout uses **tags** to categorize conversations and automatically route them into the corresponding **folders**. When you add a tag to a conversation, it appears in the matching folder so the right team member can pick it up.

| Tag | Folder | When to Use |
|---|---|---|
| `urgent` | **Urgent** | Time-sensitive issues that need immediate attention |
| `cancellation` | **Cancellation** | Customer wants to cancel an order |
| `tracking` | **Tracking** | Customer asking about shipping status or tracking info |
| `return` | **Return** | Customer wants to return a product |
| `infringement` | **Infringement** | IP or product listing infringement claims |
| `chargeback` | **Chargeback** | Payment chargeback or dispute from payment processor |
| `dispute` | **Dispute** | Customer dispute (marketplace or direct) |
| `bbb` | **BBB** | Better Business Bureau complaint |

### How to Tag a Conversation

1. Open the conversation.
2. Click the **Tags** area (or the tag icon) in the conversation header.
3. Type or select the appropriate tag.
4. The conversation automatically appears in the corresponding folder in the left sidebar.

### Best Practices

- **Tag early**: Add the tag as soon as you identify the issue type. This ensures the conversation is visible in the right folder for team members monitoring that category.
- **Combine with CS Requests**: For cancellations, returns, and tracking issues, also create a CS Request in the Order Details section so the operations team is notified.
- **One primary tag**: Use the tag that best matches the primary issue. If a customer wants both a return and a refund, use `return` and handle the refund as part of the CS Request note.

