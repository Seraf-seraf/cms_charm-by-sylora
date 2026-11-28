(function(window, document, $) {
'use strict';

var configElement = document.getElementById('cart-shipping-config');
var config = configElement ? JSON.parse(configElement.textContent || '{}') : {};

function escapeHtml(value) {
	return String(value)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#039;');
}

function renderShippingOptions(methods) {
	var html = '<div class="shipping-methods shipping-methods--modal" data-shipping-methods>';

	Object.keys(methods).forEach(function(key) {
		var method = methods[key];
		html += '<section class="shipping-methods__group">';
		html += '<p class="shipping-methods__title">' + escapeHtml(method.title) + '</p>';
		if (method.error) {
			html += '<div class="shipping-methods__status alert alert-danger" role="alert">' + escapeHtml(method.error) + '</div>';
		} else {
			html += '<div class="shipping-methods__list">';
			Object.keys(method.quote).forEach(function(quoteKey) {
				var quote = method.quote[quoteKey];
				var checked = quote.code === config.shippingMethod ? ' checked' : '';
				html += '<label class="shipping-method-card">';
				html += '<input class="shipping-method-card__control" type="radio" name="shipping_method" value="' + escapeHtml(quote.code) + '"' + checked + '>';
				html += '<span class="shipping-method-card__marker" aria-hidden="true"></span>';
				html += '<span class="shipping-method-card__title">' + escapeHtml(quote.title) + '</span>';
				html += '<span class="shipping-method-card__price">' + escapeHtml(quote.text) + '</span>';
				html += '</label>';
			});
			html += '</div>';
		}
		html += '</section>';
	});

	return html + '</div>';
}

var shippingRequestPending = false;

$('#button-quote').on('click', function() {
	if (shippingRequestPending || document.getElementById('modal-shipping')) {
		return;
	}
	var trigger = this;
	var fields = {
		country: $('#input-country'),
		zone: $('#input-zone'),
		postcode: $('#input-postcode')
	};
	var modal = $('<div id="modal-shipping" class="modal storefront-modal" role="dialog" aria-modal="true" aria-labelledby="shipping-dialog-title" tabindex="-1"></div>');
	var title = $('<h2 class="modal-title" id="shipping-dialog-title"></h2>');
	var header = $('<div class="modal-header"></div>').append(
		title,
		storefrontCloseButton('modal')
	);
	var body = $('<div class="modal-body"></div>');
	var footer = $('<div class="modal-footer"></div>').append(
		$('<button type="button" class="btn btn-default" data-dismiss="modal"></button>').text(config.buttonCancel)
	);
	var apply = $('<button type="button" id="button-shipping" class="btn btn-primary"></button>').text(config.buttonShipping);
	var loading = $('<button type="button" class="btn btn-default" disabled></button>').text(config.textLoading);
	var retry = $('<button type="button" class="btn btn-primary"></button>').text(config.buttonRetry).on('click', function() {
		header.find('.popup-close').trigger('focus');
		requestQuote();
	});
	var genericError = storefrontLabel('data-popup-error', 'error_popup_action', 'The action failed. Please try again.');
	modal.append($('<div class="modal-dialog"></div>').append(
		$('<div class="modal-content"></div>').append(header, body, footer)
	)).appendTo(document.body);

	function showQuoteError(message) {
		body.empty().append($('<div class="alert alert-danger" role="alert"></div>').append(message));
		footer.append(retry);
	}

	function finishQuote() {
		title.text(config.textTitle);
		loading.detach();
	}

	function syncShippingButton() {
		apply.prop('disabled', shippingRequestPending || modal.find('input[name="shipping_method"]:checked').length === 0);
	}

	function requestQuote() {
		shippingRequestPending = true;
		retry.detach();
		title.text(config.textQuoteLoading);
		body.empty().append($('<p role="status"></p>').text(config.textWait));
		footer.append(loading);
		$.ajax({
			url: 'index.php?route=extension/total/shipping/quote',
			type: 'post',
			data: {country_id: fields.country.val(), zone_id: fields.zone.val(), postcode: fields.postcode.val()},
			dataType: 'json',
			complete: function() {
				shippingRequestPending = false;
			},
			success: function(json) {
				if (!modal[0].isConnected) {
					return;
				}
				finishQuote();
				$('#collapse-shipping .quote-field-error').remove();
				var errors = json.error || {};
				var firstInvalid = null;
				Object.keys(fields).forEach(function(key) {
					if (errors[key]) {
						fields[key].after($('<div class="text-danger quote-field-error"></div>').text(errors[key]));
						firstInvalid = firstInvalid || fields[key];
					}
				});
				if (firstInvalid) {
					modal.one('hidden.bs.modal', function() {
						firstInvalid.trigger('focus');
					}).modal('hide');
				} else if (errors.warning) {
					showQuoteError(errors.warning);
				} else if (json.shipping_method) {
					body.html(renderShippingOptions(json.shipping_method));
					footer.append(apply);
					syncShippingButton();
				} else {
					showQuoteError(document.createTextNode(genericError));
				}
			},
			error: function() {
				if (modal[0].isConnected) {
					finishQuote();
					showQuoteError(document.createTextNode(config.textQuoteError || genericError));
				}
			}
		});
	}

	body.on('change', 'input[name="shipping_method"]', syncShippingButton);
	apply.on('click', function() {
		var selected = modal.find('input[name="shipping_method"]:checked');
		if (shippingRequestPending || !selected.length) {
			return;
		}

		shippingRequestPending = true;
		body.find('.quote-apply-error').remove();
		apply.prop('disabled', true).text(config.textLoading);
		var showApplyError = function(message) {
			if (modal[0].isConnected) {
				body.prepend($('<div class="alert alert-danger quote-apply-error" role="alert"></div>').text(message));
				body.scrollTop(0);
			}
		};

		$.ajax({
			url: 'index.php?route=extension/total/shipping/shipping',
			type: 'post',
			data: {shipping_method: selected.val()},
			dataType: 'json',
			complete: function() {
				shippingRequestPending = false;
				apply.text(config.buttonShipping);
				syncShippingButton();
			},
			success: function(json) {
				if (json.redirect) {
					window.location.assign(json.redirect);
				} else {
					showApplyError(json.warning || json.error || genericError);
				}
			},
			error: function() {
				showApplyError(genericError);
			}
		});
	});

	requestQuote();
	showStorefrontModal(modal, trigger);
});

$('select[name=\'country_id\']').on('change', function() {
	$.ajax({
		url: 'index.php?route=extension/total/shipping/country&country_id=' + this.value,
		dataType: 'json',
		beforeSend: function() {
			$('select[name=\'country_id\']').prop('disabled', true);
		},
		complete: function() {
			$('select[name=\'country_id\']').prop('disabled', false);
		},
		success: function(json) {
			if (json['postcode_required'] == '1') {
				$('input[name=\'postcode\']').parent().parent().addClass('required');
			} else {
				$('input[name=\'postcode\']').parent().parent().removeClass('required');
			}

			var html = '<option value="">' + config.textSelect + '</option>';

			if (json['zone'] && json['zone'] != '') {
				for (var i = 0; i < json['zone'].length; i++) {
					html += '<option value="' + json['zone'][i]['zone_id'] + '"';

					if (json['zone'][i]['zone_id'] == '' + config.zoneId + '') {
						html += ' selected="selected"';
					}

					html += '>' + json['zone'][i]['name'] + '</option>';
				}
			} else {
				html += '<option value="0" selected="selected">' + config.textNone + '</option>';
			}

			$('select[name=\'zone_id\']').html(html);
		},
		error: function(xhr, ajaxOptions, thrownError) {
			alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
		}
	});
});

$('select[name=\'country_id\']').trigger('change');

$('#button-voucher').on('click', function() {
	$.ajax({
		url: 'index.php?route=extension/total/voucher/voucher',
		type: 'post',
		data: 'voucher=' + encodeURIComponent($('input[name=\'voucher\']').val()),
		dataType: 'json',
		beforeSend: function() {
			$('#button-voucher').button('loading');
		},
		complete: function() {
			$('#button-voucher').button('reset');
		},
		success: function(json) {
			$('.alert-dismissible').remove();

			if (json['error']) {
				$('#content').prepend('<div class="alert alert-danger alert-dismissible"><img class="atelier-icon atelier-icon--alert" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/alert.svg" alt="" aria-hidden="true"> ' + json['error'] + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');

				$('html, body').animate({ scrollTop: 0 }, 'slow');
			}

			if (json['redirect']) {
				location = json['redirect'];
			}
		},
		error: function(xhr, ajaxOptions, thrownError) {
			alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
		}
	});
});
})(window, document, window.jQuery);
