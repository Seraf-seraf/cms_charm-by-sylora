(function(window, document, $) {
	'use strict';

	var root = document.getElementById('product-product');

	if (!root) {
		return;
	}

	var productId = root.getAttribute('data-product-id');
	var datepicker = root.getAttribute('data-datepicker');
	var uploadTimer = null;

	$('select[name="recurring_id"], input[name="quantity"]').on('change', function() {
		$.ajax({
			url: 'index.php?route=product/product/getRecurringDescription',
			type: 'post',
			data: $('input[name="product_id"], input[name="quantity"], select[name="recurring_id"]'),
			dataType: 'json',
			beforeSend: function() {
				$('#recurring-description').html('');
			},
			success: function(json) {
				$('.alert-dismissible, .text-danger').remove();

				if (json.success) {
					$('#recurring-description').html(json.success);
				}
			}
		});
	});

	$('#button-cart').on('click', function() {
		var quantity = document.getElementById('input-quantity');

		if (quantity && !quantity.checkValidity()) {
			quantity.reportValidity();
			quantity.focus();
			return;
		}

		$.ajax({
			url: 'index.php?route=checkout/cart/add',
			type: 'post',
			data: $('#product input[type="text"], #product input[type="number"], #product input[type="hidden"], #product input[type="radio"]:checked, #product input[type="checkbox"]:checked, #product select, #product textarea'),
			dataType: 'json',
			beforeSend: function() {
				$('#button-cart').button('loading');
			},
			complete: function() {
				$('#button-cart').button('reset');
			},
			success: function(json) {
				$('.alert-dismissible, .text-danger').remove();
				$('.form-group').removeClass('has-error');

				if (json.error && json.error.option) {
					for (var optionId in json.error.option) {
						if (!Object.prototype.hasOwnProperty.call(json.error.option, optionId)) {
							continue;
						}

						var element = $('#input-option' + optionId.replace('_', '-'));
						var error = $('<div class="text-danger"></div>').text(json.error.option[optionId]);

						if (element.parent().hasClass('input-group')) {
							element.parent().after(error);
						} else {
							element.after(error);
						}
					}
				}

				if (json.error && json.error.recurring) {
					$('select[name="recurring_id"]').after($('<div class="text-danger"></div>').text(json.error.recurring));
				}

				$('.text-danger').parent().addClass('has-error');

				if (!json.success) {
					return;
				}

				var cartUrl = json.cart_url || 'index.php?route=checkout/cart';
				var continueUrl = json.continue_url || 'index.php?route=product/search';
				var notification = $('<div class="alert alert-success alert-dismissible cart-notice" role="status"></div>');
				var message = $('<div class="cart-notice__message"><i class="fa fa-check-circle"></i> </div>');
				var actions = $('<div class="cart-notice__actions"></div>');

				message.append(document.createTextNode(json.success));
				actions.append($('<a class="btn btn-primary btn-sm"></a>').attr('href', cartUrl).text(json.text_cart_action || 'Перейти в корзину'));
				actions.append($('<a class="btn btn-default btn-sm"></a>').attr('href', continueUrl).text(json.text_continue_action || 'Продолжить покупки'));
				notification.append(message, actions, '<button type="button" class="close" data-dismiss="alert">&times;</button>');
				$('#content').prepend(notification);
				$('#cart > button').html('<span id="cart-total"><i class="fa fa-shopping-cart"></i> ' + json.total + '</span>');
				$('html, body').animate({scrollTop: 0}, 'slow');
				$('#cart > ul').load('index.php?route=common/cart/info ul li');
			}
		});
	});

	if ($.fn.datetimepicker) {
		$('.date').datetimepicker({language: datepicker, pickTime: false});
		$('.datetime').datetimepicker({language: datepicker, pickDate: true, pickTime: true});
		$('.time').datetimepicker({language: datepicker, pickDate: false});
	}

	$('button[id^="button-upload"]').on('click', function() {
		var button = this;
		$('#form-upload').remove();
		$('body').prepend('<form enctype="multipart/form-data" id="form-upload" hidden><input type="file" name="file"></form>');
		$('#form-upload input[name="file"]').trigger('click');
		window.clearInterval(uploadTimer);
		uploadTimer = window.setInterval(function() {
			var input = $('#form-upload input[name="file"]');

			if (!input.val()) {
				return;
			}

			window.clearInterval(uploadTimer);
			$.ajax({
				url: 'index.php?route=tool/upload',
				type: 'post',
				dataType: 'json',
				data: new FormData($('#form-upload')[0]),
				cache: false,
				contentType: false,
				processData: false,
				beforeSend: function() {
					$(button).button('loading');
				},
				complete: function() {
					$(button).button('reset');
				},
				success: function(json) {
					$('.text-danger').remove();

					if (json.error) {
						$(button).parent().find('input').after($('<div class="text-danger"></div>').text(json.error));
					}

					if (json.success) {
						window.alert(json.success);
						$(button).parent().find('input').val(json.code);
					}
				}
			});
		}, 500);
	});

	$('#review').on('click', '.pagination a', function(event) {
		event.preventDefault();
		$('#review').fadeOut('slow').load(this.href).fadeIn('slow');
	});
	$('#review').load('index.php?route=product/product/review&product_id=' + encodeURIComponent(productId), function() {
		if (window.location.hash === '#form-review') {
			var reviewForm = document.getElementById('form-review');

			if (reviewForm) {
				reviewForm.scrollIntoView({block: 'start'});
			}
		}
	});
	$(document).on('click', '#button-review', function() {
		$.ajax({
			url: 'index.php?route=product/product/write&product_id=' + encodeURIComponent(productId),
			type: 'post',
			dataType: 'json',
			data: $('#form-review').serialize(),
			beforeSend: function() {
				$('#button-review').button('loading');
			},
			complete: function() {
				$('#button-review').button('reset');
			},
			success: function(json) {
				$('.alert-dismissible').remove();

				if (json.error) {
					$('#review').after($('<div class="alert alert-danger alert-dismissible"><i class="fa fa-exclamation-circle"></i> </div>').append(document.createTextNode(json.error)));
				}

				if (json.success) {
					$('#review').after($('<div class="alert alert-success alert-dismissible"><i class="fa fa-check-circle"></i> </div>').append(document.createTextNode(json.success)));
					$('input[name="name"], textarea[name="text"]').val('');
					$('input[name="rating"]:checked, input[name="publication_consent"]').prop('checked', false);
				}
			}
		});
	});
	if ($.fn.magnificPopup) {
		$('.thumbnails').magnificPopup({
			type: 'image',
			delegate: 'a',
			gallery: {enabled: true}
		});
	}
})(window, document, window.jQuery);
