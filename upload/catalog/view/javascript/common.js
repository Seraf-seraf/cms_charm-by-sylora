function getURLVar(key) {
	var value = [];

	var query = String(document.location).split('?');

	if (query[1]) {
		var part = query[1].split('&');

		for (i = 0; i < part.length; i++) {
			var data = part[i].split('=');

			if (data[0] && data[1]) {
				value[data[0]] = data[1];
			}
		}

		if (value[key]) {
			return value[key];
		} else {
			return '';
		}
	}
}

(function() {
	var overflowClass = 'is-responsive-overflowing';
	var frame = null;
	var mutationObserver = null;
	var resizeObserver = null;

	function hasHorizontalOverflow(element) {
		return element.clientWidth > 0 && element.scrollWidth > element.clientWidth + 1;
	}

	function getPixelValue(element, property) {
		var value = window.getComputedStyle(element)[property];
		var parsed = parseFloat(value);

		return isNaN(parsed) ? 0 : parsed;
	}

	function headerNeedsCompactLayout(header) {
		if (hasHorizontalOverflow(header)) {
			return true;
		}

		var brand = header.querySelector('.site-brand');
		var nav = header.querySelector('.site-nav');
		var actions = header.querySelector('.site-actions');

		if (!brand || !nav || !actions || window.getComputedStyle(nav).position === 'fixed') {
			return false;
		}

		var links = nav.querySelectorAll('a');
		var navWidth = 0;
		var navGap = getPixelValue(nav, 'columnGap');

		for (var index = 0; index < links.length; index++) {
			navWidth += links[index].scrollWidth;
		}

		if (links.length > 1) {
			navWidth += navGap * (links.length - 1);
		}

		var headerGap = getPixelValue(header, 'columnGap');
		var requiredWidth = brand.scrollWidth + navWidth + actions.scrollWidth + (headerGap * 2);

		return requiredWidth > header.clientWidth;
	}

	function cardActionsOverflow(actions) {
		if (hasHorizontalOverflow(actions)) {
			return true;
		}

		var controls = actions.querySelectorAll('button, a');

		for (var index = 0; index < controls.length; index++) {
			if (hasHorizontalOverflow(controls[index])) {
				return true;
			}
		}

		return false;
	}

	function captchaOverflow(field) {
		var widget = field.querySelector('.smart-captcha');

		return hasHorizontalOverflow(field) || (widget && hasHorizontalOverflow(widget));
	}

	function updateIconOnlyControls() {
		var controls = document.querySelectorAll('button, a, [role="button"]');

		for (var index = 0; index < controls.length; index++) {
			var control = controls[index];
			var copy = control.cloneNode(true);
			var hiddenNodes = copy.querySelectorAll('i, svg, .fa, .glyphicon, [aria-hidden="true"], .sr-only, .visually-hidden');
			var rawText = (control.textContent || '').replace(/\s+/g, ' ').trim();

			for (var hiddenIndex = 0; hiddenIndex < hiddenNodes.length; hiddenIndex++) {
				hiddenNodes[hiddenIndex].remove();
			}

			var visibleText = (copy.textContent || '').replace(/\s+/g, ' ').trim();
			var hasIcon = control.querySelector('i, svg, .fa, .glyphicon, [class*="icon-"]') !== null;
			var isCloseSymbol = /^[×✕✖✗✘]+$/.test(rawText);
			var isIconOnly = (hasIcon && visibleText === '') || isCloseSymbol;

			control.classList.toggle('is-icon-only', isIconOnly);
		}
	}

	function updateNode(element, detector) {
		if (resizeObserver) {
			resizeObserver.observe(element);
		}

		element.classList.remove(overflowClass);
		void element.offsetWidth;
		element.classList.toggle(overflowClass, detector(element));
	}

	function updateResponsiveOverflow() {
		frame = null;
		updateIconOnlyControls();

		var headers = document.querySelectorAll('.site-header__inner');
		var cardActions = document.querySelectorAll('.catalog-card__actions');
		var captchaWidgets = document.querySelectorAll('.smart-captcha');
		var index;

		for (index = 0; index < captchaWidgets.length; index++) {
			var captchaField = captchaWidgets[index].closest('.form-group');

			if (captchaField) {
				captchaField.classList.add('smartcaptcha-field');
			}
		}

		var captchaFields = document.querySelectorAll('.smartcaptcha-field');

		for (index = 0; index < headers.length; index++) {
			updateNode(headers[index], headerNeedsCompactLayout);

			var siteHeader = headers[index].closest('.site-header');

			if (siteHeader) {
				headers[index].style.setProperty('--site-nav-top', Math.ceil(siteHeader.getBoundingClientRect().bottom) + 'px');
			}
		}

		for (index = 0; index < cardActions.length; index++) {
			updateNode(cardActions[index], cardActionsOverflow);
		}

		for (index = 0; index < captchaFields.length; index++) {
			updateNode(captchaFields[index], captchaOverflow);
		}
	}

	function scheduleResponsiveOverflowUpdate() {
		if (frame !== null) {
			return;
		}

		frame = window.requestAnimationFrame(updateResponsiveOverflow);
	}

	function startResponsiveOverflowMonitor() {
		scheduleResponsiveOverflowUpdate();

		if ('MutationObserver' in window && document.body) {
			mutationObserver = new MutationObserver(scheduleResponsiveOverflowUpdate);
			mutationObserver.observe(document.body, {
				childList: true,
				subtree: true
			});
		}

		if ('ResizeObserver' in window) {
			resizeObserver = new ResizeObserver(scheduleResponsiveOverflowUpdate);
			resizeObserver.observe(document.documentElement);
		}

		if (document.fonts && document.fonts.ready) {
			document.fonts.ready.then(scheduleResponsiveOverflowUpdate);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', startResponsiveOverflowMonitor);
	} else {
		startResponsiveOverflowMonitor();
	}

	window.addEventListener('load', scheduleResponsiveOverflowUpdate);
	window.addEventListener('resize', scheduleResponsiveOverflowUpdate);

	if (window.visualViewport) {
		window.visualViewport.addEventListener('resize', scheduleResponsiveOverflowUpdate);
	}
})();

$(document).ready(function() {
	// Highlight any found errors
	$('.text-danger').each(function() {
		var element = $(this).parent().parent();

		if (element.hasClass('form-group')) {
			element.addClass('has-error');
		}
	});

	// Currency
	$('#form-currency .currency-select').on('click', function(e) {
		e.preventDefault();

		$('#form-currency input[name=\'code\']').val($(this).attr('name'));

		$('#form-currency').submit();
	});

	// Language
	$('#form-language .language-select').on('click', function(e) {
		e.preventDefault();

		$('#form-language input[name=\'code\']').val($(this).attr('name'));

		$('#form-language').submit();
	});

	/* Search */
	$('#search input[name=\'search\']').parent().find('button').on('click', function() {
		var url = $('base').attr('href') + 'index.php?route=product/search';

		var value = $('header #search input[name=\'search\']').val();

		if (value) {
			url += '&search=' + encodeURIComponent(value);
		}

		location = url;
	});

	$('#search input[name=\'search\']').on('keydown', function(e) {
		if (e.keyCode == 13) {
			$('header #search input[name=\'search\']').parent().find('button').trigger('click');
		}
	});

	// Menu
	$('#menu .dropdown-menu').each(function() {
		var menu = $('#menu').offset();
		var dropdown = $(this).parent().offset();

		var i = (dropdown.left + $(this).outerWidth()) - (menu.left + $('#menu').outerWidth());

		if (i > 0) {
			$(this).css('margin-left', '-' + (i + 10) + 'px');
		}
	});

	// Product List
	$('#list-view').click(function() {
		$('#content .product-grid > .clearfix').remove();

		$('#content .row > .product-grid').attr('class', 'product-layout product-list col-xs-12');
		$('#grid-view').removeClass('active');
		$('#list-view').addClass('active');

		localStorage.setItem('display', 'list');
	});

	// Product Grid
	$('#grid-view').click(function() {
		// What a shame bootstrap does not take into account dynamically loaded columns
		var cols = $('#column-right, #column-left').length;

		if (cols == 2) {
			$('#content .product-list').attr('class', 'product-layout product-grid col-lg-6 col-md-6 col-sm-12 col-xs-12');
		} else if (cols == 1) {
			$('#content .product-list').attr('class', 'product-layout product-grid col-lg-4 col-md-4 col-sm-6 col-xs-12');
		} else {
			$('#content .product-list').attr('class', 'product-layout product-grid col-lg-3 col-md-3 col-sm-6 col-xs-12');
		}

		$('#list-view').removeClass('active');
		$('#grid-view').addClass('active');

		localStorage.setItem('display', 'grid');
	});

	if (localStorage.getItem('display') == 'list') {
		$('#list-view').trigger('click');
		$('#list-view').addClass('active');
	} else {
		$('#grid-view').trigger('click');
		$('#grid-view').addClass('active');
	}

	// Checkout
	$(document).on('keydown', '#collapse-checkout-option input[name=\'email\'], #collapse-checkout-option input[name=\'password\']', function(e) {
		if (e.keyCode == 13) {
			$('#collapse-checkout-option #button-login').trigger('click');
		}
	});

	// tooltips on hover
	$('[data-toggle=\'tooltip\']').tooltip({container: 'body'});

	// Makes tooltips work on ajax generated content
	$(document).ajaxStop(function() {
		$('[data-toggle=\'tooltip\']').tooltip({container: 'body'});
	});
});

function showSiteNotification(notification) {
	var host = $('#site-notifications');

	if (!host.length) {
		host = $('<div id="site-notifications" class="site-notifications container" aria-live="polite" aria-atomic="true"></div>');
		$('body').prepend(host);
	}

	host.html(notification);
}

function setProductActionLoading(trigger, loading) {
	if (!trigger) {
		return;
	}

	var element = $(trigger);

	if (loading) {
		element.data('was-disabled', element.prop('disabled'));
		element.prop('disabled', true).attr('aria-busy', 'true').addClass('is-loading');
	} else {
		element.prop('disabled', Boolean(element.data('was-disabled'))).removeAttr('aria-busy').removeClass('is-loading');
	}
}

function confirmProductAction(trigger, action) {
	if (!trigger) {
		return;
	}

	var element = $(trigger);

	if (action === 'wishlist') {
		element.attr({
			'aria-label': 'Товар в избранном',
			'aria-pressed': 'true',
			'data-original-title': 'В избранном',
			'title': 'В избранном'
		}).addClass('is-selected');
	} else {
		element.addClass('is-confirmed');
		window.setTimeout(function() {
			element.removeClass('is-confirmed');
		}, 1400);
	}
}

// Cart add remove functions
var cart = {
	'add': function(product_id, quantity, trigger) {
		$.ajax({
			url: 'index.php?route=checkout/cart/add',
			type: 'post',
			data: 'product_id=' + product_id + '&quantity=' + (typeof(quantity) != 'undefined' ? quantity : 1),
			dataType: 'json',
			beforeSend: function() {
				$('#cart > button').button('loading');
				setProductActionLoading(trigger, true);
			},
			complete: function() {
				$('#cart > button').button('reset');
				setProductActionLoading(trigger, false);
			},
			success: function(json) {
				$('.alert-dismissible, .text-danger').remove();

				if (json['redirect']) {
					location = json['redirect'];
				}

				if (json['success']) {
					document.dispatchEvent(new CustomEvent('sylora:cart-add', {detail: {product_id: product_id, quantity: (typeof(quantity) != 'undefined' ? quantity : 1)}}));
					var cartUrl = json['cart_url'] || 'index.php?route=checkout/cart';
					var continueUrl = json['continue_url'] || 'index.php?route=product/search';
					var cartText = json['text_cart_action'] || 'Перейти в корзину';
					var continueText = json['text_continue_action'] || 'Продолжить покупки';
					var notification = '<div class="alert alert-success alert-dismissible cart-notice" role="status">';

					notification += '<div class="cart-notice__message"><i class="fa fa-check-circle"></i> ' + json['success'] + '</div>';
					notification += '<div class="cart-notice__actions">';
					notification += '<a class="btn btn-primary btn-sm" href="' + cartUrl + '">' + cartText + '</a>';
					notification += '<a class="btn btn-default btn-sm" href="' + continueUrl + '">' + continueText + '</a>';
					notification += '</div>';
					notification += '<button type="button" class="close" data-dismiss="alert">&times;</button></div>';

					showSiteNotification(notification);
					confirmProductAction(trigger, 'cart');

					// Need to set timeout otherwise it wont update the total
					setTimeout(function () {
						$('#cart > button').html('<span id="cart-total"><i class="fa fa-shopping-cart"></i> ' + json['total'] + '</span>');
					}, 100);

					$('#cart > ul').load('index.php?route=common/cart/info ul li');
				}
			},
			error: function(xhr, ajaxOptions, thrownError) {
				alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
			}
		});
	},
	'update': function(key, quantity) {
		$.ajax({
			url: 'index.php?route=checkout/cart/edit',
			type: 'post',
			data: 'key=' + key + '&quantity=' + (typeof(quantity) != 'undefined' ? quantity : 1),
			dataType: 'json',
			beforeSend: function() {
				$('#cart > button').button('loading');
			},
			complete: function() {
				$('#cart > button').button('reset');
			},
			success: function(json) {
				// Need to set timeout otherwise it wont update the total
				setTimeout(function () {
					$('#cart > button').html('<span id="cart-total"><i class="fa fa-shopping-cart"></i> ' + json['total'] + '</span>');
				}, 100);

				if ($('#checkout-cart').length || getURLVar('route') == 'checkout/cart' || getURLVar('route') == 'checkout/checkout') {
					location = 'index.php?route=checkout/cart';
				} else {
					$('#cart > ul').load('index.php?route=common/cart/info ul li');
				}
			},
			error: function(xhr, ajaxOptions, thrownError) {
				alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
			}
		});
	},
	'remove': function(key, product_id, quantity) {
		$.ajax({
			url: 'index.php?route=checkout/cart/remove',
			type: 'post',
			data: 'key=' + key,
			dataType: 'json',
			beforeSend: function() {
				$('#cart > button').button('loading');
			},
			complete: function() {
				$('#cart > button').button('reset');
			},
			success: function(json) {
				document.dispatchEvent(new CustomEvent('sylora:cart-remove', {detail: {key: key, product_id: product_id, quantity: quantity}}));
				// Need to set timeout otherwise it wont update the total
				setTimeout(function () {
					$('#cart > button').html('<span id="cart-total"><i class="fa fa-shopping-cart"></i> ' + json['total'] + '</span>');
				}, 100);

				if ($('#checkout-cart').length || getURLVar('route') == 'checkout/cart' || getURLVar('route') == 'checkout/checkout') {
					location = 'index.php?route=checkout/cart';
				} else {
					$('#cart > ul').load('index.php?route=common/cart/info ul li');
				}
			},
			error: function(xhr, ajaxOptions, thrownError) {
				alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
			}
		});
	}
}

var voucher = {
	'add': function() {

	},
	'remove': function(key) {
		$.ajax({
			url: 'index.php?route=checkout/cart/remove',
			type: 'post',
			data: 'key=' + key,
			dataType: 'json',
			beforeSend: function() {
				$('#cart > button').button('loading');
			},
			complete: function() {
				$('#cart > button').button('reset');
			},
			success: function(json) {
				// Need to set timeout otherwise it wont update the total
				setTimeout(function () {
					$('#cart > button').html('<span id="cart-total"><i class="fa fa-shopping-cart"></i> ' + json['total'] + '</span>');
				}, 100);

				if (getURLVar('route') == 'checkout/cart' || getURLVar('route') == 'checkout/checkout') {
					location = 'index.php?route=checkout/cart';
				} else {
					$('#cart > ul').load('index.php?route=common/cart/info ul li');
				}
			},
			error: function(xhr, ajaxOptions, thrownError) {
				alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
			}
		});
	}
}

var wishlist = {
	'add': function(product_id, trigger) {
		$.ajax({
			url: 'index.php?route=account/wishlist/add',
			type: 'post',
			data: 'product_id=' + product_id,
			dataType: 'json',
			beforeSend: function() {
				setProductActionLoading(trigger, true);
			},
			complete: function() {
				setProductActionLoading(trigger, false);
			},
			success: function(json) {
				$('.alert-dismissible').remove();

				if (json['redirect']) {
					location = json['redirect'];
				}

				if (json['success']) {
					showSiteNotification('<div class="alert alert-success alert-dismissible" role="status"><i class="fa fa-check-circle"></i> ' + json['success'] + ' <button type="button" class="close" data-dismiss="alert">&times;</button></div>');
					confirmProductAction(trigger, 'wishlist');
					document.dispatchEvent(new CustomEvent('sylora:wishlist-add', {detail: {product_id: product_id}}));
				}

				$('#wishlist-total span').html(json['total']);
				$('#wishlist-total').attr('title', json['total']);

			},
			error: function(xhr, ajaxOptions, thrownError) {
				alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
			}
		});
	},
	'remove': function() {

	}
}

var compare = {
	'add': function(product_id) {
		$.ajax({
			url: 'index.php?route=product/compare/add',
			type: 'post',
			data: 'product_id=' + product_id,
			dataType: 'json',
			success: function(json) {
				$('.alert-dismissible').remove();

				if (json['success']) {
					$('#content').parent().before('<div class="alert alert-success alert-dismissible"><i class="fa fa-check-circle"></i> ' + json['success'] + ' <button type="button" class="close" data-dismiss="alert">&times;</button></div>');

					$('#compare-total').html(json['total']);

					$('html, body').animate({ scrollTop: 0 }, 'slow');
				}
			},
			error: function(xhr, ajaxOptions, thrownError) {
				alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
			}
		});
	},
	'remove': function() {

	}
}

/* Agree to Terms */
$(document).delegate('.agree', 'click', function(e) {
	e.preventDefault();

	$('#modal-agree').remove();

	var element = this;

	$.ajax({
		url: $(element).attr('href'),
		type: 'get',
		dataType: 'html',
		success: function(data) {
			html  = '<div id="modal-agree" class="modal">';
			html += '  <div class="modal-dialog">';
			html += '    <div class="modal-content">';
			html += '      <div class="modal-header">';
			html += '        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>';
			html += '        <h4 class="modal-title">' + $(element).text() + '</h4>';
			html += '      </div>';
			html += '      <div class="modal-body">' + data + '</div>';
			html += '    </div>';
			html += '  </div>';
			html += '</div>';

			$('body').append(html);

			$('#modal-agree').modal('show');
		}
	});
});

// Autocomplete */
(function($) {
	$.fn.autocomplete = function(option) {
		return this.each(function() {
			this.timer = null;
			this.items = new Array();

			$.extend(this, option);

			$(this).attr('autocomplete', 'off');

			// Focus
			$(this).on('focus', function() {
				this.request();
			});

			// Blur
			$(this).on('blur', function() {
				setTimeout(function(object) {
					object.hide();
				}, 200, this);
			});

			// Keydown
			$(this).on('keydown', function(event) {
				switch(event.keyCode) {
					case 27: // escape
						this.hide();
						break;
					default:
						this.request();
						break;
				}
			});

			// Click
			this.click = function(event) {
				event.preventDefault();

				value = $(event.target).parent().attr('data-value');

				if (value && this.items[value]) {
					this.select(this.items[value]);
				}
			}

			// Show
			this.show = function() {
				var pos = $(this).position();

				$(this).siblings('ul.dropdown-menu').css({
					top: pos.top + $(this).outerHeight(),
					left: pos.left
				});

				$(this).siblings('ul.dropdown-menu').show();
			}

			// Hide
			this.hide = function() {
				$(this).siblings('ul.dropdown-menu').hide();
			}

			// Request
			this.request = function() {
				clearTimeout(this.timer);

				this.timer = setTimeout(function(object) {
					object.source($(object).val(), $.proxy(object.response, object));
				}, 200, this);
			}

			// Response
			this.response = function(json) {
				html = '';

				if (json.length) {
					for (i = 0; i < json.length; i++) {
						this.items[json[i]['value']] = json[i];
					}

					for (i = 0; i < json.length; i++) {
						if (!json[i]['category']) {
							html += '<li data-value="' + json[i]['value'] + '"><a href="#">' + json[i]['label'] + '</a></li>';
						}
					}

					// Get all the ones with a categories
					var category = new Array();

					for (i = 0; i < json.length; i++) {
						if (json[i]['category']) {
							if (!category[json[i]['category']]) {
								category[json[i]['category']] = new Array();
								category[json[i]['category']]['name'] = json[i]['category'];
								category[json[i]['category']]['item'] = new Array();
							}

							category[json[i]['category']]['item'].push(json[i]);
						}
					}

					for (i in category) {
						html += '<li class="dropdown-header">' + category[i]['name'] + '</li>';

						for (j = 0; j < category[i]['item'].length; j++) {
							html += '<li data-value="' + category[i]['item'][j]['value'] + '"><a href="#">&nbsp;&nbsp;&nbsp;' + category[i]['item'][j]['label'] + '</a></li>';
						}
					}
				}

				if (html) {
					this.show();
				} else {
					this.hide();
				}

				$(this).siblings('ul.dropdown-menu').html(html);
			}

			$(this).after('<ul class="dropdown-menu"></ul>');
			$(this).siblings('ul.dropdown-menu').delegate('a', 'click', $.proxy(this.click, this));

		});
	}
})(window.jQuery);
