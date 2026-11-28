(function(window, document, $) {
	'use strict';

	var uploadTimer = null;

	function parseConfig(element) {
		try {
			return JSON.parse(element.textContent || '{}');
		} catch (error) {
			return {};
		}
	}

	function getScope(kind) {
		return kind === 'shipping_address' || kind === 'guest_shipping'
			? $('#collapse-shipping-address')
			: $('#collapse-payment-address');
	}

	function getConfig(scope) {
		var element = scope.find('.checkout-fragment-config').get(0);
		return element ? parseConfig(element) : {};
	}

	function sortFields(container, offset) {
		var fields = container.find('.form-group[data-sort]').detach();

		fields.each(function() {
			var field = $(this);
			var groups = container.find('.form-group');
			var sort = parseInt(field.attr('data-sort'), 10);
			var length = Math.max(0, groups.length - offset);

			if (sort >= 0 && sort <= length && groups.eq(sort + offset).length) {
				groups.eq(sort + offset).before(field);
			} else if (sort >= length) {
				container.find('.form-group:last').after(field);
			} else {
				container.find('.form-group:first').before(field);
			}
		});
	}

	function initializeDatepickers(scope, language) {
		if (!$.fn.datetimepicker) {
			return;
		}

		scope.find('.date:not([data-csp-datepicker])').attr('data-csp-datepicker', '1').datetimepicker({
			language: language,
			pickTime: false
		});
		scope.find('.time:not([data-csp-datepicker])').attr('data-csp-datepicker', '1').datetimepicker({
			language: language,
			pickDate: false
		});
		scope.find('.datetime:not([data-csp-datepicker])').attr('data-csp-datepicker', '1').datetimepicker({
			language: language,
			pickDate: true,
			pickTime: true
		});
	}

	function updateAddressVisibility(scope, name, existing, replacement) {
		var value = scope.find('input[name="' + name + '"]:checked').val();

		if (value === 'new') {
			scope.find(existing).hide();
			scope.find(replacement).show();
		} else {
			scope.find(existing).show();
			scope.find(replacement).hide();
		}
	}

	function updateCountry(select) {
		var scope = $(select).closest('#collapse-payment-address, #collapse-shipping-address');
		var config = getConfig(scope);
		var field = $(select);

		$.ajax({
			url: 'index.php?route=checkout/checkout/country&country_id=' + encodeURIComponent(field.val()),
			dataType: 'json',
			beforeSend: function() {
				field.prop('disabled', true);
			},
			complete: function() {
				field.prop('disabled', false);
			},
			success: function(json) {
				var postcode = scope.find('input[name="postcode"]');
				var group = postcode.closest('.form-group');
				group.toggleClass('required', json.postcode_required === '1');

				var zone = scope.find('select[name="zone_id"]');
				zone.empty().append($('<option></option>').attr('value', '').text(config.textSelect || ''));

				if (json.zone && json.zone.length) {
					for (var index = 0; index < json.zone.length; index++) {
						var item = json.zone[index];
						var option = $('<option></option>').attr('value', item.zone_id).text(item.name);

						if (String(item.zone_id) === String(config.zoneId)) {
							option.prop('selected', true);
						}

						zone.append(option);
					}
				} else {
					zone.append($('<option></option>').attr('value', '0').prop('selected', true).text(config.textNone || ''));
				}
			}
		});
	}

	function updateCustomerGroup(input) {
		var scope = $('#collapse-payment-address');

		$.getJSON('index.php?route=checkout/checkout/customfield&customer_group_id=' + encodeURIComponent(input.value), function(fields) {
			scope.find('.custom-field').hide().removeClass('required');

			for (var index = 0; index < fields.length; index++) {
				var field = fields[index];
				var element = $('#payment-custom-field' + field.custom_field_id);
				element.show().toggleClass('required', Boolean(field.required));
			}
		});
	}

	function uploadCustomField(button) {
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
					var parent = $(button).parent();
					parent.find('.text-danger').remove();

					if (json.error) {
						parent.find('input[name^="custom_field"]').after($('<div class="text-danger"></div>').text(json.error));
					}

					if (json.success) {
						window.alert(json.success);
						parent.find('input[name^="custom_field"]').val(json.code || json.file);
					}
				}
			});
		}, 500);
	}

	function initializeFragments() {
		$('.checkout-fragment-config:not([data-initialized])').each(function() {
			var element = $(this);
			var config = parseConfig(this);
			var scope = getScope(config.kind);
			element.attr('data-initialized', '1');

			if (config.kind === 'register' || config.kind === 'guest') {
				sortFields(scope.find('#account'), 0);
				sortFields(scope.find('#address'), 0);
			} else {
				sortFields(scope, 2);
			}

			initializeDatepickers(scope, config.datepicker || '');

			var customerGroup = scope.find('input[name="customer_group_id"]:checked').get(0);

			if (customerGroup) {
				updateCustomerGroup(customerGroup);
			}

			var country = scope.find('select[name="country_id"]').get(0);

			if (country) {
				updateCountry(country);
			}

			updateAddressVisibility(scope, 'payment_address', '#payment-existing', '#payment-new');
			updateAddressVisibility(scope, 'shipping_address', '#shipping-existing', '#shipping-new');
		});
	}

	$(document)
		.on('change.checkoutFragments', 'input[name="payment_address"]', function() {
			updateAddressVisibility($('#collapse-payment-address'), 'payment_address', '#payment-existing', '#payment-new');
		})
		.on('change.checkoutFragments', 'input[name="shipping_address"]', function() {
			updateAddressVisibility($('#collapse-shipping-address'), 'shipping_address', '#shipping-existing', '#shipping-new');
		})
		.on('change.checkoutFragments', '#collapse-payment-address input[name="customer_group_id"]', function() {
			updateCustomerGroup(this);
		})
		.on('change.checkoutFragments', '#collapse-payment-address select[name="country_id"], #collapse-shipping-address select[name="country_id"]', function() {
			updateCountry(this);
		})
		.on('click.checkoutFragments', 'button[id^="button-payment-custom-field"], button[id^="button-shipping-custom-field"]', function() {
			uploadCustomField(this);
		})
		.ajaxComplete(initializeFragments);

	$(initializeFragments);
})(window, document, window.jQuery);
