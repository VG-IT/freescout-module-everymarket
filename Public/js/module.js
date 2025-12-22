/**
 * Module's JavaScript.
 */

var em_customer_emails = [];
var em_orders_data = [];

function initEverymarket(customer_emails, load)
{
	em_customer_emails = customer_emails;

	if (!Array.isArray(em_customer_emails)) {
		em_customer_emails = [];
	}

	$(document).ready(function(){

		if (load) {
			emLoadOrders();
		} else {
			emLoadOrdersData();
		}

		$('.em-refresh').click(function(e) {
			emLoadOrders();
			e.preventDefault();
		});

		// Panel event handlers
		emInitPanelHandlers();
		emInitSearchPanelHandlers();
	});
}

function emLoadOrders()
{
	$('#em-orders').addClass('em-loading');

	fsAjax({
			action: 'orders',
			customer_emails: em_customer_emails,
			mailbox_id: getGlobalAttr('mailbox_id')
		},
		laroute.route('everymarket.ajax'),
		function(response) {
			if (typeof(response.status) != "undefined" && response.status == 'success'
				&& typeof(response.html) != "undefined" && response.html
			) {
				$('#em-orders').html(response.html);
				$('#em-orders').removeClass('em-loading');

				// Load orders data from embedded JSON
				emLoadOrdersData();

				$('.em-refresh').click(function(e) {
					emLoadOrders();
					e.preventDefault();
				});

				// Re-init panel handlers for newly loaded content
				emInitPanelHandlers();
			} else {
				//showAjaxError(response);
				emInitSearchPanelHandlers();
			}
		}, true
	);
}

function emLoadOrdersData()
{
	var dataElement = document.getElementById('em-orders-data');
	if (dataElement) {
		try {
			em_orders_data = JSON.parse(dataElement.textContent);
		} catch(e) {
			console.error('Failed to parse Everymarket orders data:', e);
			em_orders_data = [];
		}
	}
}

function emInitPanelHandlers()
{
	// Click handler for order items
	$(document).off('click', '.em-order-item').on('click', '.em-order-item', function(e) {
		e.preventDefault();
		var orderIndex = $(this).data('order-index');
		if (typeof orderIndex !== 'undefined' && em_orders_data[orderIndex]) {
			emShowOrderPanel(em_orders_data[orderIndex]);
		}
	});

	// Close panel on overlay click
	$(document).off('click', '#em-order-panel .em-panel-overlay').on('click', '#em-order-panel .em-panel-overlay', function() {
		emCloseOrderPanel();
	});

	// Close panel on close button click
	$(document).off('click', '#em-order-panel .em-panel-close').on('click', '#em-order-panel .em-panel-close', function() {
		emCloseOrderPanel();
	});

	// Close on ESC key
	$(document).off('keyup.em').on('keyup.em', function(e) {
		if (e.key === 'Escape' && $('#em-order-panel').hasClass('active')) {
			emCloseOrderPanel();
		}
	});
}

function emInitSearchPanelHandlers() 
{
	// Click handler for order items
	$(document).off('click', '.em-search').on('click', '.em-search', function(e) {
		e.preventDefault();
		emShowSearchPanel();
	});

	// Close panel on overlay click
	$(document).off('click', '#em-search-panel .em-panel-overlay').on('click', '#em-search-panel .em-panel-overlay', function() {
		emCloseSearchPanel();
	});

	// Close panel on close button click
	$(document).off('click', '#em-search-panel .em-panel-close').on('click', '#em-search-panel .em-panel-close', function() {
		emCloseSearchPanel();
	});

	// Close on ESC key
	$(document).off('keyup.em').on('keyup.em', function(e) {
		if (e.key === 'Escape' && $('#em-search-panel').hasClass('active')) {
			emCloseSearchPanel();
		}
	});

	$(document).off('click', '#em-search-btn').on('click', '#em-search-btn', function() {
		emSearchCustomers();
	});

	$(document).off('click', '.sidebar-block-link').on('click', '.sidebar-block-link', function(e) {
		e.preventDefault();
		var customerEmail = $(this).data('customer-email');
		if (typeof customerEmail !== 'undefined') {
			emAddCustomerEmail(customerEmail);
		}
	});
}

function emAddCustomerEmail(email)
{
	fsAjax({
			action: 'add_email',
			email: email,
			conversation_id: getGlobalAttr('conversation_id')
		},
		laroute.route('everymarket.ajax'),
		function(response) {
			console.log(response);
			if (typeof(response.status) != "undefined" && response.status == 'success') 
			{
				emCloseSearchPanel();
				location.reload();
			} else {
				$('.em-customers-list').prepend('<div class="descr-block">'+response.msg+"</div>");
				//showAjaxError(response);
			}
		}, true
	);
}

function emSearchCustomers()
{	
	$('#em-customers').addClass('em-loading');

	var search_input = $('#em-search-content').val();
	
	fsAjax({
			action: 'customers',
			search_input: search_input,
			mailbox_id: getGlobalAttr('mailbox_id')
		},
		laroute.route('everymarket.ajax'),
		function(response) {
			if (typeof(response.status) != "undefined" && response.status == 'success'
				&& typeof(response.html) != "undefined" && response.html
			) {
				$('.em-customers-list').html(response.html);
			} else {
				$('.em-customers-list').html('<div class="descr-block">'+response.msg+"</div>");
				//showAjaxError(response);
			}
			$('#em-customers').removeClass('em-loading');
		}, true
	);
}

function emShowOrderPanel(order)
{
	// Update order number in header
	$('#em-panel-title .order-number').text(order.number);

	// Build and inject order details HTML
	var html = emBuildOrderDetailsHTML(order);
	$('#em-panel-body').html(html);

	// Show panel
	$('#em-order-panel').addClass('active');
	$('body').css('overflow', 'hidden');
}

function emShowSearchPanel(order)
{
	// Show panel
	$('#em-search-panel').addClass('active');
	$('body').css('overflow', 'hidden');
}

function emCloseOrderPanel()
{
	$('#em-order-panel').removeClass('active');
	$('body').css('overflow', '');
}

function emCloseSearchPanel()
{
	$('#em-search-panel').removeClass('active');
	$('body').css('overflow', '');
}

function emBuildOrderDetailsHTML(order)
{
	var html = '';
	var shop_url = $('#em-shop-url').val();

	// Summary section
	html += '<div class="em-detail-section">';
	html += '<div class="em-detail-row">';
	html += '<div class="em-detail-label">Summary</div>';
	html += '<div class="em-detail-value">';
	html += emGetFulfillmentBadge(order.shipment_state);
	html += '</div>';
	html += '</div>';
	html += '<div class="em-detail-row">';
	html += '<div class="em-detail-label" style="width:100%;">';
	html += '<a href="' + shop_url + '/customer_service/search?q=' + order.number + '" target="_blank" class="em-panel-link">View on EM →</a>';
	html += '</div>';
	html += '</div>';
	html += '</div>';

	// Order details
	html += '<div class="em-detail-section">';
	html += '<div class="em-detail-section-title">Order Details</div>';
	html += '<div class="em-detail-row">';
	html += '<div class="em-detail-label">Order Placed</div>';
	html += '<div class="em-detail-value">' + emFormatDate(order.created_at) + '</div>';
	html += '</div>';
	html += '<div class="em-detail-row">';
	html += '<div class="em-detail-label">Payment Status</div>';
	html += '<div class="em-detail-value">' + emGetPaymentBadge(order.payment_state) + '</div>';
	html += '</div>';
	html += '</div>';

	// Shipping address
	if (order.ship_address) {
		html += '<div class="em-detail-section">';
		html += '<div class="em-detail-section-title">Shipping Address</div>';
		html += '<div class="em-address-block">';
		html += emFormatAddress(order.ship_address);
		html += '</div>';
		html += '</div>';
	}

	// Tracking information
	if (order.shipments && order.shipments.length > 0) {
		html += '<div class="em-detail-section">';
		html += '<div class="em-detail-section-title">Tracking</div>';
		for (var i = 0; i < order.shipments.length; i++) {
			var shipment = order.shipments[i];
			html += '<div class="em-detail-row">';
			html += '<div class="em-detail-label">' + (shipment.carrier || 'Shipment') + ': ' + shipment.number + '</div>';
			html += '<div class="em-detail-value">';
			html += emGetFulfillmentBadge(shipment.state || 'pending');
			html += '</div>';
			html += '</div>';
			html += '<div class="em-detail-row">';
			html += '<div class="em-detail-label">' + (shipment.selected_shipping_rate.name) + '</div>';
			html += '<div class="em-detail-value">' + (order.currency || 'USD') + ' ' + (shipment.selected_shipping_rate.cost || '0.00') + '</div>';
			html += '</div>';

			if (shipment.tracking) {
				html += '<div class="em-tracking">';
				if (shipment.tracking_url) {
					html += '<a href="' + shipment.tracking_url + '" target="_blank" class="em-tracking-number">#' + shipment.tracking + '</a>';
				} else {
					html += '<span class="em-tracking-number">#' + shipment.tracking + '</span>';
				}
				html += '</div>';
			}
		}
		html += '</div>';
	}

	// Line items
	if (order.line_items && order.line_items.length > 0) {
		html += '<div class="em-detail-section">';
		html += '<div class="em-detail-section-title">Items (' + order.line_items.length + ')</div>';
		for (var j = 0; j < order.line_items.length; j++) {
			var item = order.line_items[j];
			html += '<div class="em-line-item">';

			// Product icon/image placeholder
			html += '<div class="em-line-item-image">';
			html += '📦'; // Box emoji as placeholder
			html += '</div>';

			// Product details
			html += '<div class="em-line-item-details">';
			html += '<div class="em-line-item-name"><a href="'+ shop_url + '/products/'+item.variant.slug+'" target="_blank">' + emEscapeHtml(item.variant.name) + '</a></div>';
			if (item.variant.sku) {
				html += '<div class="em-line-item-sku">SKU: ' + emEscapeHtml(item.variant.sku) + '</div>';
			}
			html += '</div>';

			// Price
			html += '<div class="em-line-item-price">';
			html += '<div class="em-line-item-amount">' + (order.currency || 'USD') + ' ' + item.price + '</div>';
			html += '<div class="em-line-item-quantity">× ' + item.quantity + '</div>';
			html += '</div>';

			html += '</div>';
		}
		html += '</div>';
	}

	// Receipt
	html += '<div class="em-detail-section">';
	html += '<div class="em-detail-section-title">Receipt</div>';
	html += '<div class="em-receipt-totals">';

	// Subtotal
	html += '<div class="em-receipt-row">';
	html += '<div class="em-receipt-label">Subtotal';
	if (order.line_items) {
		html += ' (' + order.line_items.length + ' item' + (order.line_items.length !== 1 ? 's' : '') + ')';
	}
	html += '</div>';
	html += '<div class="em-receipt-value">' + (order.currency || 'USD') + ' ' + (order.item_total || '0.00') + '</div>';
	html += '</div>';

	// Discount
	if (order.adjustment_total && parseFloat(order.adjustment_total) > 0) {
		html += '<div class="em-receipt-row">';
		html += '<div class="em-receipt-label">Discount</div>';
		html += '<div class="em-receipt-value">-' + (order.currency || 'USD') + ' ' + order.adjustment_total + '</div>';
		html += '</div>';
	}

	// Shipping
	html += '<div class="em-receipt-row">';
	html += '<div class="em-receipt-label">Shipping</div>';
	html += '<div class="em-receipt-value">' + (order.currency || 'USD') + ' ' + (order.ship_total || '0.00') + '</div>';
	html += '</div>';

	// Tax
	if (order.tax_total && parseFloat(order.tax_total) > 0) {
		html += '<div class="em-receipt-row">';
		html += '<div class="em-receipt-label">Tax</div>';
		html += '<div class="em-receipt-value">' + (order.currency || 'USD') + ' ' + order.tax_total + '</div>';
		html += '</div>';
	}

	// Total
	html += '<div class="em-receipt-row total">';
	html += '<div class="em-receipt-label">Total</div>';
	html += '<div class="em-receipt-value">' + (order.currency || 'USD') + ' ' + order.total + '</div>';
	html += '</div>';

	// Paid by customer
	html += '<div class="em-receipt-row" style="margin-top:12px;">';
	html += '<div class="em-receipt-label">Paid by customer</div>';
	html += '<div class="em-receipt-value">' + (order.currency || 'USD') + ' ' + (order.payment_total || '0.00') + '</div>';
	html += '</div>';

	html += '</div>';
	html += '</div>';

	return html;
}

function emGetFulfillmentBadge(status)
{
	if (!status) return '<span class="em-status-badge em-status-unfulfilled">Unfulfilled</span>';

	var statusLower = status.toLowerCase().replace(/_/g, ' ');
	var statusClass = 'em-status-unfulfilled';

	if (status === 'fulfilled' || status === 'success' || status === 'delivered') {
		statusClass = 'em-status-fulfilled';
	} else if (status === 'partial' || status === 'partially_fulfilled' || status === 'label_printed' || status === 'in_transit') {
		statusClass = 'em-status-partial';
	}

	return '<span class="em-status-badge ' + statusClass + '">' + emCapitalize(statusLower) + '</span>';
}

function emGetPaymentBadge(status)
{
	if (!status) return '<span class="em-status-badge em-status-pending">Pending</span>';

	var statusLower = status.toLowerCase().replace(/_/g, ' ');
	var statusClass = 'em-status-pending';

	if (status === 'paid' || status === 'refunded' || status === 'partially_refunded') {
		statusClass = 'em-status-paid';
	}

	return '<span class="em-status-badge ' + statusClass + '">' + emCapitalize(statusLower) + '</span>';
}

function emFormatAddress(address)
{
	var parts = [];

	if (address.name) parts.push(emEscapeHtml(address.name));
	else if (address.first_name || address.last_name) {
		parts.push(emEscapeHtml((address.first_name || '') + ' ' + (address.last_name || '')).trim());
	}

	if (address.address1) parts.push(emEscapeHtml(address.address1));
	if (address.address2) parts.push(emEscapeHtml(address.address2));

	var cityLine = [];
	if (address.city) cityLine.push(emEscapeHtml(address.city));
	if (address.state_text) cityLine.push(emEscapeHtml(address.state_text));
	if (address.zipcode) cityLine.push(emEscapeHtml(address.zipcode));
	if (cityLine.length > 0) parts.push(cityLine.join(' '));

	if (address.country) parts.push(emEscapeHtml(address.country.name));

	return parts.join('<br>');
}

function emFormatDate(dateString)
{
	if (!dateString) return '';
	var date = new Date(dateString);
	return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function emCapitalize(str)
{
	return str.replace(/\b\w/g, function(char) {
		return char.toUpperCase();
	});
}

function emEscapeHtml(text)
{
	if (!text) return '';
	var map = {
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&#039;'
	};
	return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
}