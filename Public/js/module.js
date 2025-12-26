/**
 * Module's JavaScript.
 */

var em_customer_emails = [];
var em_orders_data = [];
var em_customer_id = null;
var em_user_email = '';

function initEverymarket(customer_emails, load, customer_id, user_email)
{
	em_customer_emails = customer_emails;
	em_customer_id = customer_id;
	em_user_email = user_email || '';

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
	// Preserve panel state before replacing HTML (panel is now outside #em-orders, so state is preserved automatically)
	var panelWasOpen = $('#em-order-panel').hasClass('active');
	var currentOrderNumber = null;
	if (panelWasOpen) {
		currentOrderNumber = $('#em-panel-title .order-number').text();
	}
	
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
				
				// If panel was open, refresh its content with updated data
				if (panelWasOpen && currentOrderNumber) {
					// Find the order in the updated em_orders_data
					for (var i = 0; i < em_orders_data.length; i++) {
						if (em_orders_data[i] && em_orders_data[i].number === currentOrderNumber) {
							// Update panel content with fresh data (panel stays open)
							var html = emBuildOrderDetailsHTML(em_orders_data[i]);
							$('#em-panel-body').html(html);
							// Re-init panel handlers for the updated content
							emInitPanelHandlers();
							break;
						}
					}
				}
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

	// CS Request form submission
	$(document).off('submit', '.em-cs-request-form').on('submit', '.em-cs-request-form', function(e) {
		e.preventDefault();
		var form = $(this);
		var orderNumber = form.data('order-number');
		var lineItemId = form.find('[name="line_item_id"]').val();
		var reason = form.find('[name="reason"]').val();
		var note = form.find('[name="note"]').val();
		var messageSpan = form.find('.em-form-message');
		var submitBtn = form.find('button[type="submit"]');
		
		if (!lineItemId || !reason || !note) {
			messageSpan.text('Please fill in all fields').css('color', '#d9534f');
			return;
		}
		
		submitBtn.prop('disabled', true).text('Submitting...');
		messageSpan.text('').css('color', '');
		
		// Show loading state in CS Requests section
		var csRequestsSection = form.closest('.em-detail-section');
		var loadingHtml = '<div class="em-cs-requests-loading" style="text-align: center; padding: 15px; color: #999; font-size: 12px;"><i class="glyphicon glyphicon-refresh glyphicon-spin" style="margin-right: 5px;"></i>Creating CS request...</div>';
		if (csRequestsSection.length > 0) {
			csRequestsSection.find('div[style*="padding: 15px"]').append(loadingHtml);
		}
		
		fsAjax({
			action: 'create_cs_request',
			order_number: orderNumber,
			line_item_id: lineItemId,
			reason: reason,
			note: note,
			user_email: em_user_email,
			mailbox_id: getGlobalAttr('mailbox_id')
		},
		laroute.route('everymarket.ajax'),
		function(response) {
			if (response.status === 'success') {
				messageSpan.text('Request submitted successfully').css('color', '#5cb85c');
				form[0].reset();
				// Reload orders after a short delay
				// Loading state will be removed when panel content is refreshed
				setTimeout(function() {
					emLoadOrders();
				}, 1000);
			} else {
				// Remove loading state on error
				csRequestsSection.find('.em-cs-requests-loading').remove();
				messageSpan.text(response.msg || 'Error submitting request').css('color', '#d9534f');
				submitBtn.prop('disabled', false).html('<i class="glyphicon glyphicon-send" style="margin-right: 5px;"></i>Submit Request');
			}
		},
		true, // no_loader: true - prevent global loader-main from showing
		function(xhr, status, error) {
			// Error callback for AJAX errors (404, 500, etc.)
			// Remove loading state on error
			var csRequestsSection = form.closest('.em-detail-section');
			csRequestsSection.find('.em-cs-requests-loading').remove();
			messageSpan.text('Error submitting request: ' + (error || 'Network error')).css('color', '#d9534f');
			submitBtn.prop('disabled', false).html('<i class="glyphicon glyphicon-send" style="margin-right: 5px;"></i>Submit Request');
		}
		);
	});
	
	// CS Request Event form submission (add note to existing CS request)
	$(document).off('submit', '.em-cs-request-event-form').on('submit', '.em-cs-request-event-form', function(e) {
		e.preventDefault();
		var form = $(this);
		var orderRequestId = form.data('order-request-id');
		var orderNumber = form.data('order-number');
		var note = form.find('[name="note"]').val();
		var messageSpan = form.find('.em-form-message');
		var submitBtn = form.find('button[type="submit"]');
		
		if (!orderRequestId || !note) {
			messageSpan.text('Please enter a note').css('color', '#d9534f');
			return;
		}
		
		submitBtn.prop('disabled', true).text('Adding...');
		messageSpan.text('').css('color', '');
		
		// Show loading state in events container
		var formDiv = form.parent();
		var eventsContainer = formDiv.prev('div');
		var loadingHtml = '<div class="em-events-loading" style="text-align: center; padding: 15px; color: #999; font-size: 12px;"><i class="glyphicon glyphicon-refresh glyphicon-spin" style="margin-right: 5px;"></i>Updating events...</div>';
		if (eventsContainer.length > 0) {
			eventsContainer.append(loadingHtml);
		}
		
		fsAjax({
			action: 'add_cs_request_event',
			order_request_id: orderRequestId,
			order_number: orderNumber,
			note: note,
			user_email: em_user_email,
			mailbox_id: getGlobalAttr('mailbox_id')
		},
		laroute.route('everymarket.ajax'),
		function(response) {
			if (response.status === 'success') {
				messageSpan.text('Note added successfully').css('color', '#5cb85c');
				form[0].reset();
				// Reload orders after a short delay
				// Loading state will be removed when emShowOrderPanel is called
				setTimeout(function() {
					emLoadOrders();
				}, 1000);
			} else {
				// Remove loading state on error
				formDiv.prev('div').find('.em-events-loading').remove();
				messageSpan.text(response.msg || 'Error adding note').css('color', '#d9534f');
				submitBtn.prop('disabled', false).html('<i class="glyphicon glyphicon-comment" style="margin-right: 5px;"></i>Add Note');
			}
		},
		true, // no_loader: true - prevent global loader-main from showing
		function(xhr, status, error) {
			// Error callback for AJAX errors (404, 500, etc.)
			// Remove loading state on error
			var formDiv = form.parent();
			formDiv.prev('div').find('.em-events-loading').remove();
			messageSpan.text('Error adding note: ' + (error || 'Network error')).css('color', '#d9534f');
			submitBtn.prop('disabled', false).html('<i class="glyphicon glyphicon-comment" style="margin-right: 5px;"></i>Add Note');
		}
		);
	});
	
	// Close CS Request button click handler
	$(document).off('click', '.em-close-cs-request-btn').on('click', '.em-close-cs-request-btn', function(e) {
		e.preventDefault();
		var btn = $(this);
		var orderRequestId = btn.data('order-request-id');
		var orderNumber = btn.data('order-number');
		var form = btn.closest('.em-cs-request-event-form');
		var messageSpan = form.find('.em-form-message');
		var note = form.find('[name="note"]').val();
		
		if (!orderRequestId || !orderNumber) {
			messageSpan.text('Missing required data').css('color', '#d9534f');
			return;
		}
		
		if (!confirm('Are you sure you want to close this CS request?')) {
			return;
		}
		
		btn.prop('disabled', true).text('Closing...');
		messageSpan.text('').css('color', '');
		
		// Show loading state in events container
		var formDiv = form.parent();
		var eventsContainer = formDiv.prev('div');
		var loadingHtml = '<div class="em-events-loading" style="text-align: center; padding: 15px; color: #999; font-size: 12px;"><i class="glyphicon glyphicon-refresh glyphicon-spin" style="margin-right: 5px;"></i>Updating events...</div>';
		if (eventsContainer.length > 0) {
			eventsContainer.append(loadingHtml);
		}
		
		fsAjax({
			action: 'close_cs_request',
			order_request_id: orderRequestId,
			order_number: orderNumber,
			note: note,
			user_email: em_user_email,
			mailbox_id: getGlobalAttr('mailbox_id')
		},
		laroute.route('everymarket.ajax'),
		function(response) {
			if (response.status === 'success') {
				messageSpan.text('CS request closed successfully').css('color', '#5cb85c');
				form[0].reset();
				// Reload orders after a short delay
				// Loading state will be removed when emShowOrderPanel is called
				setTimeout(function() {
					emLoadOrders();
				}, 1000);
			} else {
				// Remove loading state on error
				formDiv.prev('div').find('.em-events-loading').remove();
				messageSpan.text(response.msg || 'Error closing CS request').css('color', '#d9534f');
				btn.prop('disabled', false).text('Close Request');
			}
		},
		true, // no_loader: true - prevent global loader-main from showing
		function(xhr, status, error) {
			// Error callback for AJAX errors (404, 500, etc.)
			// Remove loading state on error
			var formDiv = form.parent();
			formDiv.prev('div').find('.em-events-loading').remove();
			messageSpan.text('Error closing CS request: ' + (error || 'Network error')).css('color', '#d9534f');
			btn.prop('disabled', false).text('Close');
		}
		);
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
	
	// Remove any loading states in events containers and CS requests sections
	$('#em-panel-body .em-events-loading').remove();
	$('#em-panel-body .em-cs-requests-loading').remove();

	// Show panel
	$('.navbar-static-top').css('z-index', 3);
	$('#em-order-panel').addClass('active');
	$('body').css('overflow', 'hidden');
}

function emShowSearchPanel(order)
{
	// Show panel
	$('.navbar-static-top').css('z-index', 3);
	$('#em-search-panel').addClass('active');
	$('body').css('overflow', 'hidden');
}

function emCloseOrderPanel()
{
	$('.navbar-static-top').css('z-index', 10);
	$('#em-order-panel').removeClass('active');
	$('body').css('overflow', '');
}

function emCloseSearchPanel()
{
	$('.navbar-static-top').css('z-index', 10);
	$('#em-search-panel').removeClass('active');
	$('body').css('overflow', '');
}

function emBuildOrderDetailsHTML(order)
{
	var html = '';
	var shop_url = $('#em-shop-url').val();
	console.log(order)

	// Order details
	html += '<div class="em-detail-section">';
	html += '<div class="em-detail-section-title">Order Details</div>';

	html += '<div class="em-detail-row">';
	html += '<div class="em-detail-label">Order Status</div>';
	html += '<div class="em-detail-value">';
	html += emGetFulfillmentBadge(order.shipment_state);
	html += '</div>';
	html += '</div>';

	html += '<div class="em-detail-row">';
	html += '<div class="em-detail-label">Order Placed</div>';
	html += '<div class="em-detail-value">' + emFormatDate(order.created_at) + '</div>';
	html += '</div>';

	html += '<div class="em-detail-row">';
	html += '<div class="em-detail-label">Payment Status</div>';
	html += '<div class="em-detail-value">' + emGetPaymentBadge(order.payment_state) + '</div>';
	html += '</div>';
	html += '<div class="em-detail-row">';
	html += '<div class="em-detail-label">';
	html += '<a href="' + shop_url + '/customer_service/search?q=' + order.number + '" target="_blank" class="em-panel-link">View on EM →</a>';
	html += '</div>';
	html += '<div class="em-detail-value">';
	html += '<a href="' + shop_url + '/admin/orders/' + order.number + '/invoice.pdf" target="_blank" class="em-panel-link"><span class="em-status-badge em-status-fulfilled">Download Invoice</span></a>';
	html += '</div>';
	html += '</div>';
	html += '</div>';

	// Order requests section
	if (order.cs_requests.length > 0) {
		html += '<div class="em-detail-section">';
		html += '<div class="em-detail-section-title">';
		html += '<strong>CS Requests</strong>';
		html += '</div>';
		
		for(var i = 0; i < order.cs_requests.length; i++) {
			var cs_request = order.cs_requests[i];
			if(cs_request.events && cs_request.events.length > 0) {
				// Find matching line item by variant_id to get image
				var productImageUrl = null;
				if (cs_request.product && cs_request.product.variant_id && order.line_items && order.line_items.length > 0) {
					for (var k = 0; k < order.line_items.length; k++) {
						var line_item = order.line_items[k];
						if (line_item.variant && line_item.variant.id == cs_request.product.variant_id) {
							if (line_item.variant.images && line_item.variant.images.length > 0) {
								productImageUrl = line_item.variant.images[0].large_url || line_item.variant.images[0].url;
							}
							break;
						}
					}
				}
				
				// CS Request header
				html += '<div style="margin-top: 15px; padding: 12px; background-color: #f8f9fa; border-left: 3px solid #95BF47; border-radius: 3px;">';
				html += '<div style="margin-bottom: 10px;">';
				
				// Product information section
				if (cs_request.product) {
					html += '<div style="display: flex; align-items: flex-start; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0;">';
					// Product image from line_items variant
					if (productImageUrl) {
						html += '<div style="margin-right: 12px; flex-shrink: 0;">';
						html += '<img src="' + emEscapeHtml(productImageUrl) + '" style="width: 50px; height: 50px; object-fit: cover; border-radius: 3px; border: 1px solid #e0e0e0;" />';
						html += '</div>';
					}
					// Product details
					html += '<div style="flex: 1; min-width: 0;">';
					html += '<div style="font-weight: 600; font-size: 13px; color: #333; margin-bottom: 4px;">';
					if (cs_request.product.name) {
						html += '<span style="color: #333;">' + emEscapeHtml(cs_request.product.name) + '</span>';
					}
					html += '</div>';
					if (cs_request.product.sku) {
						html += '<div style="font-size: 11px; color: #999; margin-bottom: 4px;">SKU: ' + emEscapeHtml(cs_request.product.sku) + '</div>';
					}
					html += '<div style="font-size: 12px; color: #666;">';
					if (cs_request.product.price !== undefined && cs_request.product.price !== null) {
						html += '<strong>Price:</strong> ' + (order.currency || '') + ' ' + parseFloat(cs_request.product.price).toFixed(2);
					}
					if (cs_request.product.quantity !== undefined && cs_request.product.quantity !== null) {
						html += ' <strong>Qty:</strong> ' + cs_request.product.quantity;
					}
					html += '</div>';
					html += '</div>';
					html += '</div>';
				} else {
					// Fallback if product info not available
					html += '<div style="font-weight: 600; font-size: 13px; color: #333; margin-bottom: 4px;">';
					html += '<span style="color: #666;">Item #' + (cs_request.order_item_id || 'N/A') + '</span>';
					html += '</div>';
				}
				
				html += '<div style="font-size: 12px; color: #666; margin-bottom: 6px;">';
				html += '<strong>Reason:</strong> ' + (cs_request.request ? cs_request.request.reason : 'N/A');
				html += '</div>';
				if (cs_request.role && cs_request.created_by) {
					html += '<div style="font-size: 11px; color: #999;">';
					html += 'Created by ' + cs_request.role + ' ' + cs_request.created_by;
					html += '</div>';
				}
				html += '</div>';
				
				// Events list
				html += '<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #e0e0e0;">';
				for(var j = 0; j < cs_request.events.length; j++) {
					var event = cs_request.events[j];
					var event_date = '';
					if (event.created_at) {
						// Format date if available
						try {
							var date_obj = new Date(event.created_at);
							event_date = date_obj.toLocaleDateString() + ' ' + date_obj.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
						} catch(e) {
							event_date = event.created_at;
						}
					}
					
					html += '<div style="margin-bottom: 12px; padding: 8px; background-color: #fff; border: 1px solid #e8e8e8; border-radius: 3px;">';
					html += '<div style="font-size: 12px; line-height: 1.5; color: #333; margin-bottom: 6px;">';
					html += event.note || 'N/A';
					html += '</div>';
					html += '<div style="font-size: 11px; color: #999; display: flex; justify-content: space-between; align-items: center;">';
					if (event.action && event.role && event.created_by) {
						html += '<span>' + event.action + ' by ' + event.role + ' ' + event.created_by + '</span>';
					}
					if (event_date) {
						html += '<span style="margin-left: auto;">' + event_date + '</span>';
					}
					html += '</div>';
					html += '</div>';
				}
				html += '</div>';
				
				if (!(cs_request.request.status == "finalized")) {
					html += '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0;">';
					html += '<form class="em-cs-request-event-form" data-order-request-id="' + (cs_request.request.id || '') + '" data-line-item-id="' + (cs_request.order_item_id || '') + '" data-order-number="' + (order.number || '') + '">';
					html += '<div class="form-group" style="margin-bottom: 10px;">';
					html += '<label style="font-weight: 600; font-size: 12px; color: #333; margin-bottom: 6px; display: block;">Add Note</label>';
					html += '<textarea name="note" class="form-control" rows="3" placeholder="Enter your note..." style="font-size: 12px; resize: vertical;" required></textarea>';
					html += '</div>';
					html += '<div class="form-group" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">';
					html += '<button type="submit" class="btn btn-primary btn-sm" style="font-size: 12px;">';
					html += '<i class="glyphicon glyphicon-comment" style="margin-right: 5px;"></i>Add Note';
					html += '</button>';
					html += '<button type="button" class="btn btn-default btn-sm em-close-cs-request-btn" data-order-request-id="' + (cs_request.request.id || '') + '" data-order-number="' + (order.number || '') + '" style="font-size: 12px;">';
					html += 'Close Request';
					html += '</button>';
					html += '<span class="em-form-message" style="margin-left: auto; font-size: 11px;"></span>';
					html += '</div>';
					html += '</form>';
					html += '</div>';
				}
				html += '</div>';
			}		
		}

		html += '</div>';
	} else {
		// Show form to create new CS request when none exist
		html += '<div class="em-detail-section">';
		html += '<div class="em-detail-section-title">';
		html += '<strong>CS Requests</strong>';
		html += '</div>';
		html += '<div style="padding: 15px;">';
		html += '<form class="em-cs-request-form" data-order-number="' + (order.number || '') + '">';
		html += '<div class="form-group" style="margin-bottom: 15px;">';
		html += '<label for="cs_request_line_item" style="font-weight: 600; font-size: 13px; color: #333; margin-bottom: 8px; display: block;">Order Item</label>';
		html += '<select id="cs_request_line_item" name="line_item_id" class="form-control" style="font-size: 13px;" required>';
		html += '<option value="">-- Select Item --</option>';
		if (order.line_items && order.line_items.length > 0) {
			for (var k = 0; k < order.line_items.length; k++) {
				var line_item = order.line_items[k];
				var item_id = line_item.id || line_item.line_item_id || '';
				var item_name = (line_item.variant && line_item.variant.name) ? line_item.variant.name : 'Item #' + (k + 1);
				var item_sku = (line_item.variant && line_item.variant.sku) ? ' (SKU: ' + line_item.variant.sku + ')' : '';
				html += '<option value="' + item_id + '">' + emEscapeHtml(item_name) + item_sku + '</option>';
			}
		}
		html += '</select>';
		html += '</div>';
		html += '<div class="form-group" style="margin-bottom: 15px;">';
		html += '<label for="cs_request_reason" style="font-weight: 600; font-size: 13px; color: #333; margin-bottom: 8px; display: block;">Reason</label>';
		html += '<select id="cs_request_reason" name="reason" class="form-control" style="font-size: 13px;" required>';
		html += '<option value="">-- Select Reason --</option>';
		html += '<option value="cancel">Cancel</option>';
		html += '<option value="refund">Refund</option>';
		html += '<option value="return">Return</option>';
		html += '<option value="tracking_info">Tracking Info</option>';
		html += '<option value="others">Others</option>';
		html += '</select>';
		html += '</div>';
		html += '<div class="form-group" style="margin-bottom: 15px;">';
		html += '<label for="cs_request_note" style="font-weight: 600; font-size: 13px; color: #333; margin-bottom: 8px; display: block;">Note</label>';
		html += '<textarea id="cs_request_note" name="note" class="form-control" rows="4" placeholder="Enter your request details..." style="font-size: 13px; resize: vertical;" required></textarea>';
		html += '</div>';
		html += '<div class="form-group" style="margin-bottom: 0;">';
		html += '<button type="submit" class="btn btn-primary btn-sm" style="font-size: 13px;">';
		html += '<i class="glyphicon glyphicon-send" style="margin-right: 5px;"></i>Submit Request';
		html += '</button>';
		html += '<span class="em-form-message" style="margin-left: 10px; font-size: 12px;"></span>';
		html += '</div>';
		html += '</form>';
		html += '</div>';
		html += '</div>';
	}

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
			html += item.variant.images.length > 0 ? '<img src="' + item.variant.images[0].large_url + '" height="60" />' : '📦'; // Box emoji as placeholder
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
		html += '<div class="em-receipt-label">Adjustment</div>';
		html += '<div class="em-receipt-value">' + (order.currency || 'USD') + ' ' + order.adjustment_total + '</div>';
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