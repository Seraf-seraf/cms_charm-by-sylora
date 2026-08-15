(function(window, document) {
	'use strict';

	function getStoredTheme() {
		try {
			return window.localStorage ? window.localStorage.getItem('sylora-theme') : null;
		} catch (error) {
			return null;
		}
	}

	var storedTheme = getStoredTheme();
	var systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
	document.documentElement.setAttribute('data-theme', storedTheme || (systemDark ? 'dark' : 'light'));

	document.addEventListener('click', function(event) {
		var target = event.target.closest ? event.target.closest('[data-cart-add], [data-cart-remove], [data-voucher-remove], [data-wishlist-add], [data-compare-add], [data-confirm], [data-paypal-modal], [data-payment-confirm]') : null;

		if (!target) {
			return;
		}

		if (target.hasAttribute('data-confirm') && !window.confirm(target.getAttribute('data-confirm'))) {
			event.preventDefault();
			return;
		}

		if (target.hasAttribute('data-cart-add')) {
			event.preventDefault();
			window.cart.add(target.getAttribute('data-cart-add'), target.getAttribute('data-quantity') || 1, target);
		} else if (target.hasAttribute('data-cart-remove')) {
			event.preventDefault();
			window.cart.remove(target.getAttribute('data-cart-remove'), target.getAttribute('data-product-id'), target.getAttribute('data-quantity'));
		} else if (target.hasAttribute('data-voucher-remove')) {
			event.preventDefault();
			window.voucher.remove(target.getAttribute('data-voucher-remove'));
		} else if (target.hasAttribute('data-wishlist-add')) {
			event.preventDefault();
			window.wishlist.add(target.getAttribute('data-wishlist-add'), target);
		} else if (target.hasAttribute('data-compare-add')) {
			event.preventDefault();
			window.compare.add(target.getAttribute('data-compare-add'));
		} else if (target.hasAttribute('data-paypal-modal') && typeof window.loadPayPalModal === 'function') {
			event.preventDefault();
			window.loadPayPalModal();
		} else if (target.hasAttribute('data-payment-confirm')) {
			event.preventDefault();
			submitPayment(target);
		}
	});

	function showPaymentError(message) {
		var alert = $('<div class="alert alert-danger alert-dismissible"><i class="fa fa-exclamation-circle"></i> <span></span><button type="button" class="close" data-dismiss="alert">&times;</button></div>');
		alert.find('span').text(message);
		$('#collapse-checkout-confirm .panel-body').prepend(alert);
	}

	function submitPayment(button) {
		var element = $(button);
		var expectsJson = button.getAttribute('data-payment-request') === 'json';

		$.ajax({
			type: button.getAttribute('data-payment-method') || 'post',
			url: button.getAttribute('data-payment-confirm'),
			dataType: expectsJson ? 'json' : undefined,
			cache: false,
			beforeSend: function() {
				element.button('loading');
			},
			complete: function() {
				element.button('reset');
			},
			success: function(response) {
				$('.alert-dismissible').remove();

				if (expectsJson && response.error) {
					showPaymentError(response.error);
				}

				if (expectsJson && response.redirect) {
					window.location.assign(response.redirect);
				} else if (!expectsJson && button.getAttribute('data-payment-continue')) {
					window.location.assign(button.getAttribute('data-payment-continue'));
				}
			},
			error: function() {
				var message = button.getAttribute('data-payment-error');

				if (message) {
					$('.alert-dismissible').remove();
					showPaymentError(message);
				}
			}
		});
	}

	function followCheckoutRedirect() {
		var redirect = document.querySelector('[data-checkout-redirect]');

		if (redirect) {
			window.location.assign(redirect.getAttribute('data-checkout-redirect'));
		}
	}

	document.addEventListener('change', function(event) {
		var target = event.target;

		if (target.hasAttribute('data-location-select')) {
			window.location.assign(target.value);
		}

		if (target.hasAttribute('data-eway-payment-option') && typeof window.select_eWAYPaymentOption === 'function') {
			window.select_eWAYPaymentOption(target.getAttribute('data-eway-payment-option'));
		}
	});

	function initializeHeader() {
		var root = document.documentElement;
		var themeButton = document.querySelector('.theme-toggle');
		var nav = document.getElementById('site-nav');
		var navButton = document.querySelector('.mobile-nav-toggle');
		var backdrop = document.querySelector('.site-nav-backdrop');

		function setStoredTheme(theme) {
			try {
				if (window.localStorage) {
					window.localStorage.setItem('sylora-theme', theme);
				}
			} catch (error) {
				return;
			}
		}

		function syncThemeButton() {
			var dark = root.getAttribute('data-theme') === 'dark';

			if (themeButton) {
				themeButton.setAttribute('aria-pressed', dark ? 'true' : 'false');
				themeButton.innerHTML = '<i class="fa ' + (dark ? 'fa-sun-o' : 'fa-moon-o') + '" aria-hidden="true"></i>';
			}
		}

		function syncNavButton(isOpen) {
			if (!navButton) {
				return;
			}

			navButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
			navButton.setAttribute('aria-label', isOpen ? 'Закрыть меню' : 'Открыть меню');
			navButton.innerHTML = '<i class="fa ' + (isOpen ? 'fa-times' : 'fa-bars') + '" aria-hidden="true"></i>';
		}

		function closeNav() {
			if (!nav || !navButton || !backdrop) {
				return;
			}

			nav.classList.remove('is-open');
			syncNavButton(false);
			backdrop.hidden = true;
		}

		if (themeButton) {
			syncThemeButton();
			themeButton.addEventListener('click', function() {
				var nextTheme = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
				root.setAttribute('data-theme', nextTheme);
				setStoredTheme(nextTheme);
				syncThemeButton();
			});
		}

		if (!navButton || !nav || !backdrop) {
			return;
		}

		navButton.addEventListener('click', function() {
			var isOpen = nav.classList.toggle('is-open');
			syncNavButton(isOpen);
			backdrop.hidden = !isOpen;
		});
		backdrop.addEventListener('click', closeNav);
		nav.addEventListener('click', function(event) {
			if (event.target.tagName === 'A') {
				closeNav();
			}
		});
		document.addEventListener('keydown', function(event) {
			if (event.key === 'Escape') {
				closeNav();
			}

			if (event.key !== 'Tab' || !nav.classList.contains('is-open')) {
				return;
			}

			var focusable = Array.prototype.slice.call(nav.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])')).concat(navButton);

			if (!focusable.length) {
				return;
			}

			var first = focusable[0];
			var last = focusable[focusable.length - 1];

			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		});
	}

	function initializeCatalog() {
		var lastQuickViewTrigger = null;
		var filterDetails = document.querySelector('.catalog-filters');
		var desktopFilters = window.matchMedia('(min-width: 992px)');

		function syncFilterDisclosure(event) {
			if (!filterDetails) {
				return;
			}

			if (desktopFilters.matches) {
				filterDetails.open = true;
			} else if (event && filterDetails.getAttribute('data-active-filters') !== 'true') {
				filterDetails.open = false;
			}
		}

		syncFilterDisclosure();

		if (typeof desktopFilters.addEventListener === 'function') {
			desktopFilters.addEventListener('change', syncFilterDisclosure);
		} else if (typeof desktopFilters.addListener === 'function') {
			desktopFilters.addListener(syncFilterDisclosure);
		}

		document.addEventListener('click', function(event) {
			var trigger = event.target.closest ? event.target.closest('.js-quick-view-open') : null;

			if (!trigger) {
				return;
			}

			var dialog = document.getElementById(trigger.getAttribute('data-dialog-id'));

			if (!dialog || typeof dialog.showModal !== 'function') {
				return;
			}

			lastQuickViewTrigger = trigger;
			trigger.setAttribute('aria-expanded', 'true');
			dialog.showModal();

			var focusable = dialog.querySelector('a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])');

			if (focusable) {
				focusable.focus();
			}
		});
		document.addEventListener('close', function(event) {
			if (!event.target.classList || !event.target.classList.contains('quick-view') || !lastQuickViewTrigger) {
				return;
			}

			lastQuickViewTrigger.setAttribute('aria-expanded', 'false');
			lastQuickViewTrigger.focus();
			lastQuickViewTrigger = null;
		}, true);
		document.addEventListener('submit', function(event) {
			var minInput = event.target.querySelector ? event.target.querySelector('input[name="price_min"]') : null;
			var maxInput = event.target.querySelector ? event.target.querySelector('input[name="price_max"]') : null;

			if (!minInput || !maxInput) {
				return;
			}

			var minValue = parseFloat(minInput.value);
			var maxValue = parseFloat(maxInput.value);

			if (!isNaN(minValue) && !isNaN(maxValue) && minValue > maxValue) {
				minInput.value = maxValue;
				maxInput.value = minValue;
			}
		});

		var categorySelect = document.querySelector('select[name="category_id"]');
		var subcategoryInput = document.querySelector('input[name="sub_category"]');

		if (categorySelect && subcategoryInput) {
			var syncSubcategory = function() {
				subcategoryInput.disabled = categorySelect.value === '0';
			};
			categorySelect.addEventListener('change', syncSubcategory);
			syncSubcategory();
		}
	}

	function initializeContact() {
		var form = document.querySelector('.sylora-form[data-contact-error]');

		if (!form) {
			return;
		}

		form.addEventListener('submit', function(event) {
			var email = form.querySelector('#input-email');
			var phone = form.querySelector('#input-telephone');
			var hasContact = (email && email.value.trim()) || (phone && phone.value.trim());

			if (!hasContact) {
				event.preventDefault();

				if (email) {
					email.setCustomValidity(form.getAttribute('data-contact-error'));
					email.reportValidity();
				}
			} else if (email) {
				email.setCustomValidity('');
			}
		});
		form.addEventListener('input', function() {
			var email = form.querySelector('#input-email');

			if (email) {
				email.setCustomValidity('');
			}
		});

		var firstInvalid = form.querySelector('[aria-invalid="true"]');

		if (firstInvalid) {
			firstInvalid.focus();
		}
	}

	function initializeCarousels() {
		$('[data-slideshow]').each(function() {
			$(this).swiper({
				mode: 'horizontal',
				slidesPerView: 1,
				pagination: this.getAttribute('data-pagination'),
				paginationClickable: true,
				nextButton: '.slideshow .swiper-button-next',
				prevButton: '.slideshow .swiper-button-prev',
				spaceBetween: 30,
				autoplay: 5000,
				autoplayDisableOnInteraction: false,
				loop: true
			});
		});
		$('[data-banner]').each(function() {
			$(this).swiper({
				effect: 'fade',
				autoplay: 2500,
				autoplayDisableOnInteraction: false
			});
		});
	}

	function initialize() {
		initializeHeader();
		initializeCatalog();
		initializeContact();
		initializeCarousels();
		followCheckoutRedirect();
		$(document).ajaxComplete(followCheckoutRedirect);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize);
	} else {
		initialize();
	}
})(window, document);
