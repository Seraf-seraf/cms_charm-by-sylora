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

$('#button-quote').on('click', function() {
	$.ajax({
		url: 'index.php?route=extension/total/shipping/quote',
		type: 'post',
		data: 'country_id=' + $('select[name=\'country_id\']').val() + '&zone_id=' + $('select[name=\'zone_id\']').val() + '&postcode=' + encodeURIComponent($('input[name=\'postcode\']').val()),
		dataType: 'json',
		beforeSend: function() {
			$('#button-quote').button('loading');
		},
		complete: function() {
			$('#button-quote').button('reset');
		},
		success: function(json) {
			$('.alert-dismissible, .text-danger').remove();

			if (json['error']) {
				if (json['error']['warning']) {
					$('#content').prepend('<div class="alert alert-danger alert-dismissible"><i class="fa fa-exclamation-circle"></i> ' + json['error']['warning'] + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');

					$('html, body').animate({ scrollTop: 0 }, 'slow');
				}

				if (json['error']['country']) {
					$('select[name=\'country_id\']').after('<div class="text-danger">' + json['error']['country'] + '</div>');
				}

				if (json['error']['zone']) {
					$('select[name=\'zone_id\']').after('<div class="text-danger">' + json['error']['zone'] + '</div>');
				}

				if (json['error']['postcode']) {
					$('input[name=\'postcode\']').after('<div class="text-danger">' + json['error']['postcode'] + '</div>');
				}
			}

			if (json['shipping_method']) {
				$('#modal-shipping').remove();

				var html = '<div id="modal-shipping" class="modal">';
				html += '  <div class="modal-dialog">';
				html += '    <div class="modal-content">';
				html += '      <div class="modal-header">';
				html += '        <h4 class="modal-title">' + config.textShippingMethod + '</h4>';
				html += '      </div>';
				html += '      <div class="modal-body">';
				html += '        <div class="shipping-methods shipping-methods--modal" data-shipping-methods aria-busy="false">';

				for (var i in json['shipping_method']) {
					html += '<section class="shipping-methods__group">';
					html += '<p class="shipping-methods__title">' + escapeHtml(json['shipping_method'][i]['title']) + '</p>';

					if (!json['shipping_method'][i]['error']) {
						html += '<div class="shipping-methods__list">';
						for (var j in json['shipping_method'][i]['quote']) {
							var quote = json['shipping_method'][i]['quote'][j];
							html += '<label class="shipping-method-card">';

							if (quote['code'] == '' + config.shippingMethod + '') {
								html += '<input class="shipping-method-card__control" type="radio" name="shipping_method" value="' + escapeHtml(quote['code']) + '" checked="checked" />';
							} else {
								html += '<input class="shipping-method-card__control" type="radio" name="shipping_method" value="' + escapeHtml(quote['code']) + '" />';
							}

							html += '<span class="shipping-method-card__marker" aria-hidden="true"></span>';
							html += '<span class="shipping-method-card__title">' + escapeHtml(quote['title']) + '</span>';
							html += '<span class="shipping-method-card__price">' + escapeHtml(quote['text']) + '</span>';
							html += '</label>';
						}
						html += '</div>';
					} else {
						html += '<div class="shipping-methods__status alert alert-danger alert-dismissible" role="alert">' + escapeHtml(json['shipping_method'][i]['error']) + '</div>';
					}
					html += '</section>';
				}

				html += '        </div>';
				html += '      </div>';
				html += '      <div class="modal-footer">';
				html += '        <button type="button" class="btn btn-default" data-dismiss="modal">' + config.buttonCancel + '</button>';

				if (config.shippingMethod) {
				html += '        <input type="button" value="' + config.buttonShipping + '" id="button-shipping" data-loading-text="' + config.textLoading + '" class="btn btn-primary" />';
				} else {
				html += '        <input type="button" value="' + config.buttonShipping + '" id="button-shipping" data-loading-text="' + config.textLoading + '" class="btn btn-primary" disabled="disabled" />';
				}

				html += '      </div>';
				html += '    </div>';
				html += '  </div>';
				html += '</div> ';

				$('body').append(html);

				function syncShippingButton() {
					$('#button-shipping').prop('disabled', $('input[name=\'shipping_method\']:checked').length === 0);
				}

				$('#modal-shipping').modal('show');
				syncShippingButton();

				$('input[name=\'shipping_method\']').on('change', function() {
					syncShippingButton();
				});
			}
		},
		error: function(xhr, ajaxOptions, thrownError) {
			alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
		}
	});
});

})(window, document, window.jQuery);
$(document).delegate('#button-shipping', 'click', function() {
	$.ajax({
		url: 'index.php?route=extension/total/shipping/shipping',
		type: 'post',
		data: 'shipping_method=' + encodeURIComponent($('input[name=\'shipping_method\']:checked').val()),
		dataType: 'json',
		beforeSend: function() {
			$('#button-shipping').button('loading');
		},
		complete: function() {
			$('#button-shipping').button('reset');
		},
		success: function(json) {
			$('.alert-dismissible').remove();

			if (json['error']) {
				$('#content').prepend('<div class="alert alert-danger alert-dismissible"><i class="fa fa-exclamation-circle"></i> ' + json['error'] + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');

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
				$('#content').prepend('<div class="alert alert-danger alert-dismissible"><i class="fa fa-exclamation-circle"></i> ' + json['error'] + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');

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
