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
			// Orders from cache; server already ran single-order sync on page render
			emLoadOrderDetails();
		}

		$('.em-refresh').click(function(e) {
			emLoadOrders();
			e.preventDefault();
		});

		// Order Details refresh (loads from Order Number custom field)
		$(document).off('click', '.em-order-details-refresh-btn').on('click', '.em-order-details-refresh-btn', function(e) {
			e.preventDefault();
			emLoadOrderDetails();
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
			mailbox_id: getGlobalAttr('mailbox_id'),
			conversation_id: getGlobalAttr('conversation_id')
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

				// Single order → fill the custom field input if empty
				if (em_orders_data.length === 1 && em_orders_data[0] && em_orders_data[0].number) {
					emFillOrderNumberField(em_orders_data[0].number);
				}

				// Server ran single-order sync; now load Order Details
				emLoadOrderDetails();
				
				// If panel was open, refresh its content with updated data
				if (panelWasOpen && currentOrderNumber) {
					// Find the order in the updated em_orders_data
					for (var i = 0; i < em_orders_data.length; i++) {
						if (em_orders_data[i] && em_orders_data[i].number === currentOrderNumber) {
							// Update panel content with fresh data (panel stays open)
							var html = emBuildOrderDetailsHTML(em_orders_data[i], false);
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
				// Still load Order Details if custom field was already set
				emLoadOrderDetails();
			}
		}, true
	);
}

function emLoadOrderDetails()
{
	var contentEl = $('#em-order-details-content');
	var loaderSrc = ($('img[src*="loader-tiny"]').first().attr('src')) || '/img/loader-tiny.gif';
	contentEl.html('<img src="' + loaderSrc + '" />');

	fsAjax({
			action: 'order_details',
			conversation_id: getGlobalAttr('conversation_id'),
			mailbox_id: getGlobalAttr('mailbox_id')
		},
		laroute.route('everymarket.ajax'),
		function(response) {
			if (response.html) {
				contentEl.html(response.html);
			} else if (response.order && response.shop_url) {
				$('#em-order-details-shop-url').val(response.shop_url);
				var html = emBuildOrderDetailsHTML(response.order);
				contentEl.html(html);
				emInitPanelHandlers();
				emFillOrderNumberField(response.order.number);
			} else {
				contentEl.html('<span class="text-help">' + (response.msg || 'No Order Found') + '</span>');
			}
		},
		true
	);
}

function emFillOrderNumberField(orderNumber)
{
	if (!orderNumber) return;
	$('#custom-fields-form .custom-field').each(function() {
		var label = $.trim($(this).find('.text-help').first().text());
		if (label === 'Order Number') {
			var input = $(this).find('input[type="text"]');
			if (input.length && !$.trim(input.val())) {
				input.val(orderNumber).trigger('change');
			}
		}
	});
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

/**
 * True if CS note HTML is empty (no text and no images).
 */
function emCsNoteHtmlIsEmpty(html) {
	if (!html) {
		return true;
	}
	var text = $('<div>').html(html).text().replace(/\u00a0/g, ' ').trim();
	if (text.length) {
		return false;
	}
	return !/<img/i.test(html);
}

/** Sync Summernote content into the underlying textarea before read/submit. */
function emCsNoteFieldSync($textarea) {
	if ($textarea.data('summernote')) {
		$textarea.val($textarea.summernote('code'));
	}
}

/** Clear CS note field (Summernote or plain textarea). */
function emCsNoteFieldReset($textarea) {
	if ($textarea.data('summernote')) {
		$textarea.summernote('code', '');
	} else {
		$textarea.val('');
	}
}

/**
 * Append uploaded file as a linked file name only (no image preview in Summernote).
 */
function emCsNoteAppendFileLink($ta, fileName, url) {
	var safeUrl = String(url).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
	var anchor = '<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer">' + emEscapeHtml(fileName) + '</a>';
	if ($ta.data('summernote')) {
		$ta.summernote('pasteHTML', anchor + '<br>');
	} else {
		var cur = $ta.val() || '';
		if (cur.length && !/\n$/.test(cur)) {
			cur += '\n';
		}
		$ta.val(cur + anchor + '\n');
	}
}

/**
 * Upload one file to FreeScout attachment storage; on success append to note editor.
 */
function emUploadCsNoteFile(file, $noteField, statusEl, onComplete) {
	if (!file || typeof file.type === 'undefined') {
		if (onComplete) {
			onComplete(false);
		}
		return;
	}
	ajaxSetup();
	var data = new FormData();
	data.append('file', file);
	data.append('attach', '1');

	$.ajax({
		url: laroute.route('conversations.upload'),
		data: data,
		cache: false,
		contentType: false,
		processData: false,
		type: 'POST',
		success: function(response) {
			if (typeof response.url === 'undefined' || !response.url
				|| typeof response.status === 'undefined' || response.status !== 'success') {
				if (typeof showFloatingAlert === 'function') {
					showFloatingAlert('error', (response && response.msg) ? response.msg : 'Upload failed');
				}
				if (onComplete) {
					onComplete(false);
				}
				return;
			}
			emCsNoteAppendFileLink($noteField, file.name, response.url);
			if (onComplete) {
				onComplete(true);
			}
		},
		error: function() {
			if (typeof showFloatingAlert === 'function') {
				showFloatingAlert('error', typeof Lang !== 'undefined' ? Lang.get('messages.ajax_error') : 'Upload failed');
			}
			if (onComplete) {
				onComplete(false);
			}
		}
	});
}

function emUploadCsNoteFilesQueue(files, index, $noteField, statusEl) {
	if (!files || !files.length) {
		return;
	}
	if (index >= files.length) {
		$(statusEl).text('');
		return;
	}
	var file = files[index];
	$(statusEl).text('Uploading ' + (index + 1) + '/' + files.length + ': ' + file.name + '…');
	emUploadCsNoteFile(file, $noteField, statusEl, function() {
		emUploadCsNoteFilesQueue(files, index + 1, $noteField, statusEl);
	});
}

/**
 * Summernote toolbar: attach files (same pattern as main reply EditorAttachmentButton).
 */
function emCsNoteAttachmentButton(context) {
	var ui = $.summernote.ui;
	var attachTooltip = (typeof Lang !== 'undefined' && Lang.get)
		? Lang.get('messages.upload_attachments')
		: 'Attach files';

	return ui.button({
		contents: '<i class="glyphicon glyphicon-paperclip"></i>',
		tooltip: attachTooltip,
		className: 'note-btn-em-cs-attach',
		container: 'body',
		click: function() {
			var $note = context.layoutInfo.note;
			var element = document.createElement('div');
			element.innerHTML = '<input type="file" multiple>';
			var fileInput = element.firstChild;
			fileInput.addEventListener('change', function() {
				if (fileInput.files && fileInput.files.length) {
					var form = $note.closest('form');
					var statusEl = form.find('.em-cs-note-upload-status');
					emUploadCsNoteFilesQueue(fileInput.files, 0, $note, statusEl);
				}
				fileInput.value = '';
			});
			fileInput.click();
		}
	}).render();
}

/**
 * Turn CS note fields into Summernote (same stack as conversation reply).
 */
function emInitCsNoteEditors() {
	if (typeof $.summernote === 'undefined') {
		return;
	}
	$('#em-order-panel textarea.em-cs-note-editor, #em-order-details-content textarea.em-cs-note-editor').each(function() {
		var $ta = $(this);
		if ($ta.next('.note-editor').length) {
			return;
		}
		$ta.summernote({
			minHeight: 100,
			dialogsInBody: true,
			disableResizeEditor: true,
			followingToolbar: false,
			disableDragAndDrop: true,
			placeholder: $ta.attr('placeholder') || '',
			toolbar: [
				['style', ['emCsAttach', 'bold', 'italic', 'underline', 'color']],
				['para', ['ul', 'ol']],
				['insert', ['link', 'picture']]
			],
			buttons: {
				emCsAttach: emCsNoteAttachmentButton
			},
			callbacks: {
				onImageUpload: function(files) {
					if (!files) {
						return;
					}
					for (var i = 0; i < files.length; i++) {
						emUploadCsNoteFile(files[i], $ta, null, null);
					}
				}
			}
		});
		if (typeof fsFixEditorCodeSaving === 'function') {
			fsFixEditorCodeSaving($ta);
		}
	});
}

function emInitPanelHandlers()
{
	// Collapsible sections (e.g. Inbound Shipments)
	$(document).off('click', '.em-collapsible-toggle').on('click', '.em-collapsible-toggle', function(e) {
		e.preventDefault();
		var $wrap = $(this).closest('.em-collapsible');
		if (!$wrap.length) {
			return;
		}
		var collapsed = $wrap.toggleClass('em-collapsed').hasClass('em-collapsed');
		$(this).attr('aria-expanded', collapsed ? 'false' : 'true');
		var $arrow = $(this).find('.em-collapsible-arrow');
		if (collapsed) {
			$arrow.removeClass('glyphicon-chevron-down').addClass('glyphicon-chevron-right');
		} else {
			$arrow.removeClass('glyphicon-chevron-right').addClass('glyphicon-chevron-down');
		}
	});
	$(document).off('keydown', '.em-collapsible-toggle').on('keydown', '.em-collapsible-toggle', function(e) {
		if (e.key === 'Enter' || e.key === ' ') {
			e.preventDefault();
			$(this).trigger('click');
		}
	});

	// Click handler for order items
	$(document).off('click', '.em-order-item').on('click', '.em-order-item', function(e) {
		e.preventDefault();
		if ($(e.target).closest('.em-order-copy').length) return;
		var orderIndex = $(this).data('order-index');
		if (typeof orderIndex !== 'undefined' && em_orders_data[orderIndex]) {
			emShowOrderPanel(em_orders_data[orderIndex]);
		}
	});

	// Copy order number (without # prefix) when copy icon clicked
	$(document).off('click', '.em-order-copy').on('click', '.em-order-copy', function(e) {
		e.preventDefault();
		e.stopPropagation();
		var orderNumber = $(this).data('order-number');
		if (orderNumber) {
			emCopyToClipboard(String(orderNumber));
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
		var $noteField = form.find('textarea[name="note"]');
		emCsNoteFieldSync($noteField);
		var note = $noteField.val();
		var messageSpan = form.find('.em-form-message');
		var submitBtn = form.find('button[type="submit"]');
		
		if (!lineItemId || !reason || emCsNoteHtmlIsEmpty(note)) {
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
			mailbox_id: getGlobalAttr('mailbox_id'),
			conversation_id: getGlobalAttr('conversation_id')
		},
		laroute.route('everymarket.ajax'),
		function(response) {
			if (response.status === 'success') {
				messageSpan.text('Request submitted successfully').css('color', '#5cb85c');
				form[0].reset();
				emCsNoteFieldReset(form.find('textarea[name="note"]'));
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
		var $noteField = form.find('textarea[name="note"]');
		emCsNoteFieldSync($noteField);
		var note = $noteField.val();
		var messageSpan = form.find('.em-form-message');
		var submitBtn = form.find('button[type="submit"]');
		
		if (!orderRequestId || emCsNoteHtmlIsEmpty(note)) {
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
			mailbox_id: getGlobalAttr('mailbox_id'),
			conversation_id: getGlobalAttr('conversation_id')
		},
		laroute.route('everymarket.ajax'),
		function(response) {
			if (response.status === 'success') {
				messageSpan.text('Note added successfully').css('color', '#5cb85c');
				form[0].reset();
				emCsNoteFieldReset(form.find('textarea[name="note"]'));
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
		var $noteField = form.find('textarea[name="note"]');
		emCsNoteFieldSync($noteField);
		var note = $noteField.val();
		
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
			mailbox_id: getGlobalAttr('mailbox_id'),
			conversation_id: getGlobalAttr('conversation_id')
		},
		laroute.route('everymarket.ajax'),
		function(response) {
			if (response.status === 'success') {
				messageSpan.text('CS request closed successfully').css('color', '#5cb85c');
				form[0].reset();
				emCsNoteFieldReset(form.find('textarea[name="note"]'));
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

	emInitCsNoteEditors();
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

	// Build and inject order details HTML (no CS Requests in Order History panel)
	var html = emBuildOrderDetailsHTML(order, false);
	$('#em-panel-body').html(html);
	
	// Remove any loading states in events containers and CS requests sections
	$('#em-panel-body .em-events-loading').remove();
	$('#em-panel-body .em-cs-requests-loading').remove();

	emInitCsNoteEditors();

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

function emBuildOrderDetailsHTML(order, includeCsRequests)
{
	var html = '';
	var shop_url = ($('#em-shop-url').val() || $('#em-order-details-shop-url').val() || '');
	if (includeCsRequests === undefined) includeCsRequests = true;

	// CS Requests (at top) - only in Order Details section, not in Order History panel
	if (includeCsRequests) {
		if (order.cs_requests && order.cs_requests.length > 0) {
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
					html += '<textarea name="note" class="form-control em-cs-note-editor" rows="3" placeholder="Enter your note..." style="font-size: 12px; resize: vertical;"></textarea>';
					html += '<span class="em-cs-note-upload-status" style="font-size: 11px; color: #999; display: block; margin-top: 4px;"></span>';
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
		html += '<textarea id="cs_request_note" name="note" class="form-control em-cs-note-editor" rows="4" placeholder="Enter your request details..." style="font-size: 13px; resize: vertical;"></textarea>';
		html += '<span class="em-cs-note-upload-status" style="font-size: 12px; color: #999; display: block; margin-top: 4px;"></span>';
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

			// Onway qty for this line item
			var onwayInfo = emGetOnwayForLineItem(order, item.id);
			if (onwayInfo) {
				html += '<div class="em-line-item-sku" style="margin-top: 2px;">';
				html += '<span style="color: #6d7175;">EC SKU: ' + emEscapeHtml(onwayInfo.ec_product_sku) + '</span>';
				html += ' &nbsp; <span style="color: #108043; font-weight: 500;">Onway: ' + onwayInfo.onway_qty + '</span>';
				html += ' &nbsp; <span style="color: #6d7175;">Sellable: ' + onwayInfo.sellable_qty + '</span>';
				html += '</div>';
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

	// Inbound ASN tracking (from onway_items)
	if (order.onway_items && order.onway_items.length > 0) {
		var allPackages = [];
		for (var oi = 0; oi < order.onway_items.length; oi++) {
			var owItem = order.onway_items[oi];
			if (owItem.packages && owItem.packages.length > 0) {
				for (var pi = 0; pi < owItem.packages.length; pi++) {
					var pkg = owItem.packages[pi];
					allPackages.push({
						ec_sku: owItem.ec_product_sku || '',
						tracking_number: pkg.tracking_number || '',
						carrier: pkg.carrier || '',
						status: pkg.receiving_status || '',
						receiving_code: pkg.receiving_code || '',
						warehouse_code: pkg.warehouse_code || '',
						eta_date: pkg.eta_date || '',
						received_at: pkg.received_at || '',
						warehouse_receiving_time: pkg.warehouse_receiving_time || '',
						warehouse_receiving_complete_time: pkg.warehouse_receiving_complete_time || '',
						warehouse_shelf_time: pkg.warehouse_shelf_time || '',
						quantity: pkg.quantity || 0,
						received_quantity: pkg.received_quantity || 0,
						putaway_qty: pkg.putaway_qty || 0,
						sent_qty: pkg.sent_qty || 0
					});
				}
			}
		}
		if (allPackages.length > 0) {
			html += '<div class="em-detail-section em-collapsible em-inbound-shipments em-collapsed">';
			html += '<div class="em-detail-section-title em-collapsible-toggle" role="button" tabindex="0" aria-expanded="false">';
			html += '<span class="em-collapsible-arrow glyphicon glyphicon-chevron-right" aria-hidden="true"></span>';
			html += '<span>Inbound Shipments (ASN)</span>';
			html += '</div>';
			html += '<div class="em-collapsible-body">';
			for (var ap = 0; ap < allPackages.length; ap++) {
				var apkg = allPackages[ap];
				html += '<div style="padding: 8px 0;' + (ap < allPackages.length - 1 ? ' border-bottom: 1px solid #eee;' : '') + '">';

				// Row 1: ASN code + status badge
				html += '<div class="em-detail-row" style="padding: 2px 0;">';
				html += '<div class="em-detail-label" style="font-size: 12px; font-weight: 600;">' + emEscapeHtml(apkg.receiving_code || 'ASN') + '</div>';
				html += '<div class="em-detail-value">' + emGetAsnStatusBadge(apkg.status) + '</div>';
				html += '</div>';

				// Row 2: Carrier + tracking
				html += '<div class="em-detail-row" style="padding: 2px 0;">';
				html += '<div class="em-detail-label" style="font-size: 12px;">' + emEscapeHtml(apkg.carrier || 'Carrier N/A') + '</div>';
				html += '<div class="em-detail-value" style="font-family: monospace; font-size: 12px;">' + (apkg.tracking_number
					? emFormatTrackingNumberHtml(apkg.tracking_number, apkg.carrier, { everymarket: true })
					: emEscapeHtml('N/A')) + '</div>';
				html += '</div>';

				// Row 3: EC SKU + Warehouse
				html += '<div class="em-detail-row" style="padding: 2px 0;">';
				html += '<div class="em-detail-label" style="font-size: 11px; color: #6d7175;">SKU: ' + emEscapeHtml(apkg.ec_sku) + '</div>';
				if (apkg.warehouse_code) {
					html += '<div class="em-detail-value" style="font-size: 11px; color: #6d7175;">WH: ' + emEscapeHtml(apkg.warehouse_code) + '</div>';
				}
				html += '</div>';

				// Row 4: Quantity breakdown — Sent / Received / Putaway / Total
				var inStock = apkg.received_quantity - apkg.sent_qty;
				if (inStock < 0) inStock = 0;
				html += '<div style="display: flex; gap: 8px; flex-wrap: wrap; padding: 4px 0; font-size: 11px;">';
				html += '<span style="color: #637381;">Sent: <b>' + apkg.quantity + '</b></span>';
				html += '<span style="color: #637381;">Rcvd: <b>' + apkg.received_quantity + '</b></span>';
				html += '<span style="color: #637381;">Shelved: <b>' + apkg.putaway_qty + '</b></span>';
				html += '<span style="color: ' + (apkg.sent_qty > 0 ? '#bf0711' : '#637381') + ';">Shipped Out: <b>' + apkg.sent_qty + '</b></span>';
				html += '<span style="color: ' + (inStock > 0 ? '#108043' : '#637381') + ';">In Stock: <b>' + inStock + '</b></span>';
				html += '</div>';

				// Row 5: Timeline dates
				html += '<div style="font-size: 11px; color: #637381; padding: 2px 0;">';
				if (apkg.eta_date) {
					html += '<div>ETA: ' + emFormatDate(apkg.eta_date) + '</div>';
				}
				if (apkg.warehouse_receiving_time) {
					html += '<div>WH Receiving: ' + emFormatDate(apkg.warehouse_receiving_time) + '</div>';
				}
				if (apkg.warehouse_receiving_complete_time) {
					html += '<div>WH Received: ' + emFormatDate(apkg.warehouse_receiving_complete_time) + '</div>';
				}
				if (apkg.warehouse_shelf_time) {
					html += '<div>Shelved: ' + emFormatDate(apkg.warehouse_shelf_time) + '</div>';
				}
				html += '</div>';

				html += '</div>';
			}
			html += '</div>';
			html += '</div>';
		}
	}

	// Shipments (per order item: carrier/tracking, eccang, shipstation, fulfill orders)
	if (order.order_item_shipments && order.order_item_shipments.length > 0) {
		html += '<div class="em-detail-section">';
		html += '<div class="em-detail-section-title">Shipments</div>';
		for (var si = 0; si < order.order_item_shipments.length; si++) {
			var shipInfo = order.order_item_shipments[si];
			var shipItemName = shipInfo.s_sku || ('Item #' + (si + 1));

			// Find matching line item name
			if (order.line_items && shipInfo.line_item_id) {
				for (var li = 0; li < order.line_items.length; li++) {
					if (order.line_items[li].id == shipInfo.line_item_id) {
						shipItemName = (order.line_items[li].variant && order.line_items[li].variant.name)
							? order.line_items[li].variant.name
							: shipItemName;
						break;
					}
				}
			}

			html += '<div style="padding: 10px 0;' + (si < order.order_item_shipments.length - 1 ? ' border-bottom: 1px solid #f1f2f3;' : '') + '">';

			// Item header with status
			html += '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">';
			html += '<div style="font-weight: 600; font-size: 12px; color: #333;">' + emEscapeHtml(shipItemName) + '</div>';
			if (shipInfo.status) {
				html += '<div>' + emGetFulfillmentBadge(shipInfo.status) + '</div>';
			}
			html += '</div>';

			// Carrier + tracking
			if (shipInfo.s_tracking) {
				html += '<div class="em-detail-row" style="padding: 2px 0;">';
				html += '<div class="em-detail-label" style="font-size: 12px;">' + emEscapeHtml(shipInfo.s_carrier || 'Carrier') + '</div>';
				html += '<div class="em-detail-value" style="font-family: monospace; font-size: 12px;">' + emFormatTrackingNumberHtml(shipInfo.s_tracking, shipInfo.s_carrier, { everymarket: false }) + '</div>';
				html += '</div>';
			}

			// International tracking
			if (shipInfo.s_intl_forward && shipInfo.s_intl_tracking) {
				html += '<div class="em-detail-row" style="padding: 2px 0;">';
				html += '<div class="em-detail-label" style="font-size: 12px;">Intl Tracking</div>';
				var intlCarrier = shipInfo.s_intl_carrier || shipInfo.s_carrier || '';
				html += '<div class="em-detail-value" style="font-family: monospace; font-size: 12px;">' + emFormatTrackingNumberHtml(shipInfo.s_intl_tracking, intlCarrier, { everymarket: true }) + '</div>';
				html += '</div>';
			}

			// Shipping method
			if (shipInfo.s_shipping_method) {
				html += '<div class="em-detail-row" style="padding: 2px 0;">';
				html += '<div class="em-detail-label" style="font-size: 12px;">Method</div>';
				html += '<div class="em-detail-value" style="font-size: 12px;">' + emEscapeHtml(shipInfo.s_shipping_method) + '</div>';
				html += '</div>';
			}

			// Eccang shipments
			if (shipInfo.eccang_shipments && shipInfo.eccang_shipments.length > 0) {
				for (var ei = 0; ei < shipInfo.eccang_shipments.length; ei++) {
					var eccang = shipInfo.eccang_shipments[ei];
					html += '<div style="margin-top: 6px; padding: 8px; background-color: #f8f9fa; border-radius: 3px; border-left: 3px solid #95BF47;">';
					html += '<div style="font-size: 11px; color: #999; margin-bottom: 4px;">Eccang Order</div>';
					html += '<div class="em-detail-row" style="padding: 2px 0;">';
					html += '<div class="em-detail-label" style="font-size: 12px;">' + emEscapeHtml(eccang.order_code) + '</div>';
					html += '<div class="em-detail-value">' + emGetFulfillmentBadge(eccang.status || '') + '</div>';
					html += '</div>';
					if (eccang.tracking_number) {
						html += '<div class="em-detail-row" style="padding: 2px 0;">';
						html += '<div class="em-detail-label" style="font-size: 12px;">Tracking</div>';
						html += '<div class="em-detail-value" style="font-family: monospace; font-size: 12px;">' + emFormatTrackingNumberHtml(eccang.tracking_number, eccang.carrier || '', { everymarket: true }) + '</div>';
						html += '</div>';
					}
					if (eccang.items && eccang.items.length > 0) {
						var itemsStr = eccang.items.map(function(item) {
							return emEscapeHtml(item.product_sku) + ' (' + item.quantity + ')';
						}).join(', ');
						html += '<div style="font-size: 11px; color: #6d7175; margin-top: 2px;">Items: ' + itemsStr + '</div>';
					}
					html += '</div>';
				}
			}

			// Shipstation
			if (shipInfo.shipstation && shipInfo.shipstation.order_number) {
				html += '<div style="margin-top: 6px; padding: 8px; background-color: #f8f9fa; border-radius: 3px; border-left: 3px solid #5bc0de;">';
				html += '<div style="font-size: 11px; color: #999; margin-bottom: 4px;">Shipstation</div>';
				html += '<div class="em-detail-row" style="padding: 2px 0;">';
				html += '<div class="em-detail-label" style="font-size: 12px;">' + emEscapeHtml(shipInfo.shipstation.order_number) + '</div>';
				if (shipInfo.shipstation.tracking_number) {
					html += '<div class="em-detail-value" style="font-family: monospace; font-size: 12px;">' + emFormatTrackingNumberHtml(shipInfo.shipstation.tracking_number, shipInfo.shipstation.carrier_code || shipInfo.shipstation.carrier || shipInfo.s_carrier || '', { everymarket: false }) + '</div>';
				} else {
					html += '<div class="em-detail-value" style="font-size: 11px; color: #999;">No Shipment</div>';
				}
				html += '</div>';
				html += '</div>';
			}

			// Fulfill orders (STC / direct purchase)
			if (shipInfo.fulfill_orders && shipInfo.fulfill_orders.length > 0) {
				for (var fi = 0; fi < shipInfo.fulfill_orders.length; fi++) {
					var fo = shipInfo.fulfill_orders[fi];
					var foLabel = fo.stc ? 'STC Fulfill' : 'Fulfill Order';
					html += '<div style="margin-top: 6px; padding: 8px; background-color: #f8f9fa; border-radius: 3px; border-left: 3px solid #f0ad4e;">';
					html += '<div style="font-size: 11px; color: #999; margin-bottom: 4px;">' + foLabel + '</div>';
					if (fo.buy_tracking) {
						html += '<div class="em-detail-row" style="padding: 2px 0;">';
						html += '<div class="em-detail-label" style="font-size: 12px;">' + emEscapeHtml(fo.buy_carrier || 'Carrier') + '</div>';
						html += '<div class="em-detail-value" style="font-family: monospace; font-size: 12px;">' + emFormatTrackingNumberHtml(fo.buy_tracking, fo.buy_carrier, { everymarket: true }) + '</div>';
						html += '</div>';
					}
					if (fo.forward_tracking) {
						html += '<div class="em-detail-row" style="padding: 2px 0;">';
						html += '<div class="em-detail-label" style="font-size: 12px;">' + emEscapeHtml(fo.forward_carrier || 'Forward') + '</div>';
						html += '<div class="em-detail-value" style="font-family: monospace; font-size: 12px;">' + emFormatTrackingNumberHtml(fo.forward_tracking, fo.forward_carrier, { everymarket: true }) + '</div>';
						html += '</div>';
					}
					if (fo.buy_order_number) {
						html += '<div style="font-size: 11px; color: #6d7175; margin-top: 2px;">Order: ' + emEscapeHtml(fo.buy_order_number) + '</div>';
					}
					html += '</div>';
				}
			}

			// No shipment info at all
			if (!shipInfo.s_tracking && (!shipInfo.eccang_shipments || shipInfo.eccang_shipments.length === 0) && !shipInfo.shipstation && (!shipInfo.fulfill_orders || shipInfo.fulfill_orders.length === 0)) {
				html += '<div style="font-size: 12px; color: #999;">Tracking unavailable</div>';
			}

			html += '</div>';
		}
		html += '</div>';
	}

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
				html += '<div style="display: flex; flex-direction: column; gap: 4px; align-items: flex-start;">';
				html += emFormatTrackingNumberHtml(shipment.tracking, shipment.carrier, {
					everymarket: true,
					primaryUrl: shipment.tracking_url || '',
					linkClass: 'em-tracking-number'
				});
				html += '</div></div>';
			}
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

/**
 * Intl numbers that use Everymarket’s tracker (India Post style, e.g. CP942981811IN).
 * Domestic / carrier numbers (e.g. USPS) must not use EM — link the carrier instead.
 */
function emIsEverymarketIntlTrackingNumber(trackingNumber) {
	var t = String(trackingNumber || '').trim();
	return t.length > 0 && /^CP/i.test(t);
}

/**
 * Everymarket public tracking page (only meaningful for emIsEverymarketIntlTrackingNumber).
 */
function emEverymarketTrackingUrl(trackingNumber) {
	var t = String(trackingNumber || '').trim();
	if (!t) {
		return '';
	}
	return 'https://everymarket.com/tracking/' + encodeURIComponent(t);
}

/**
 * Carrier tracking URL (USPS, DHL, FedEx, UPS) from carrier name + number.
 */
function emCarrierTrackingUrl(carrier, trackingNumber) {
	var t = String(trackingNumber || '').trim();
	if (!t) {
		return '';
	}
	var c = String(carrier || '').toLowerCase();
	if (c.indexOf('usps') !== -1 || c.indexOf('united states postal') !== -1) {
		return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' + encodeURIComponent(t);
	}
	if (c.indexOf('dhl') !== -1) {
		return 'https://www.dhl.com/en/express/tracking.html?AWB=' + encodeURIComponent(t);
	}
	if (c.indexOf('fedex') !== -1 || c.indexOf('fed ex') !== -1) {
		return 'https://www.fedex.com/fedextrack/?trknbr=' + encodeURIComponent(t);
	}
	if (c.indexOf('ups') !== -1 && c.indexOf('usps') === -1) {
		return 'https://www.ups.com/track?tracknum=' + encodeURIComponent(t);
	}
	return '';
}

function emCarrierLinkLabel(carrier) {
	var c = String(carrier || '').toLowerCase();
	if (c.indexOf('usps') !== -1 || c.indexOf('united states postal') !== -1) {
		return 'USPS';
	}
	if (c.indexOf('dhl') !== -1) {
		return 'DHL';
	}
	if (c.indexOf('fedex') !== -1 || c.indexOf('fed ex') !== -1) {
		return 'FedEx';
	}
	if (c.indexOf('ups') !== -1) {
		return 'UPS';
	}
	return 'Carrier';
}

/**
 * Renders the tracking number as the link text (not a separate "Everymarket" label).
 * opts.everymarket — allow Everymarket URL only for CP… intl numbers (see emIsEverymarketIntlTrackingNumber)
 * opts.primaryUrl — optional first link (e.g. API tracking_url); when set, number links here
 * opts.linkClass — extra classes on the primary anchor (e.g. em-tracking-number in Order Details)
 */
function emFormatTrackingNumberHtml(trackingNumber, carrier, opts) {
	opts = opts || {};
	var t = String(trackingNumber || '').trim();
	if (!t) {
		return '';
	}
	var escaped = emEscapeHtml(t);
	var carrierUrl = emCarrierTrackingUrl(carrier, t);
	var emUrl = emEverymarketTrackingUrl(t);
	var useEm = opts.everymarket && emIsEverymarketIntlTrackingNumber(t);

	var primaryHref = '';
	if (opts.primaryUrl && String(opts.primaryUrl).trim()) {
		primaryHref = String(opts.primaryUrl).trim();
	} else if (useEm) {
		primaryHref = emUrl;
	} else if (carrierUrl) {
		primaryHref = carrierUrl;
	}

	var anchorClass = 'em-panel-link';
	if (opts.linkClass) {
		anchorClass += ' ' + opts.linkClass;
	}

	var main = primaryHref
		? '<a href="' + primaryHref + '" target="_blank" rel="noopener noreferrer" class="' + anchorClass + '" style="font-family: monospace; font-size: 12px;">' + escaped + '</a>'
		: (opts.linkClass
			? '<span class="' + opts.linkClass + '" style="font-family: monospace; font-size: 12px;">' + escaped + '</span>'
			: '<span style="font-family: monospace; font-size: 12px;">' + escaped + '</span>');

	// No second-row carrier link when primary is Everymarket: order carrier is often the domestic
	// service (e.g. USPS) while CP… numbers are intl (India Post, Japan Post, etc.) — misleading.
	return main;
}

function emGetAsnStatusBadge(status)
{
	if (!status) return '<span class="em-status-badge em-status-unfulfilled">Unknown</span>';
	var s = status.toLowerCase();
	var statusClass = 'em-status-unfulfilled';
	if (s === 'received' || s === 'shelved' || s === 'completed') {
		statusClass = 'em-status-fulfilled';
	} else if (s === 'in transit' || s === 'in_transit' || s === 'shipped' || s === 'receiving') {
		statusClass = 'em-status-partial';
	}
	return '<span class="em-status-badge ' + statusClass + '">' + emCapitalize(s.replace(/_/g, ' ')) + '</span>';
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

function emCopyToClipboard(text)
{
	if (navigator.clipboard && navigator.clipboard.writeText) {
		navigator.clipboard.writeText(text).then(function() {
			if (typeof showFloatingAlert === 'function') {
				showFloatingAlert('success', 'Copied to clipboard');
			}
		}).catch(function() {
			emCopyToClipboardFallback(text);
		});
	} else {
		emCopyToClipboardFallback(text);
	}
}

function emCopyToClipboardFallback(text)
{
	var ta = document.createElement('textarea');
	ta.value = text;
	ta.style.position = 'fixed';
	ta.style.left = '-9999px';
	document.body.appendChild(ta);
	ta.select();
	try {
		document.execCommand('copy');
		if (typeof showFloatingAlert === 'function') {
			showFloatingAlert('success', 'Copied to clipboard');
		}
	} catch (e) {}
	document.body.removeChild(ta);
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

function emGetOnwayForLineItem(order, lineItemId)
{
	if (!order.onway_items || !order.onway_items.length) return null;
	for (var i = 0; i < order.onway_items.length; i++) {
		if (order.onway_items[i].line_item_id == lineItemId) {
			return order.onway_items[i];
		}
	}
	return null;
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