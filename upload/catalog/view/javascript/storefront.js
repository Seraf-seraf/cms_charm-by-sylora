(function(window, document) {
	'use strict';

	$(document).on('click', '[data-mini-cart-close]', function(event) {
		event.stopPropagation();
		var trigger = $('#cart > [data-toggle="dropdown"]');
		trigger.dropdown('toggle').trigger('focus');
	});

	document.addEventListener('click', function(event) {
		var target = event.target.closest ? event.target.closest('[data-cart-add], [data-cart-remove], [data-voucher-remove], [data-wishlist-add], [data-compare-add], [data-confirm], [data-paypal-modal], [data-payment-confirm]') : null;

		if (!target) {
			return;
		}

		if (target.hasAttribute('data-confirm')) {
			var addressDialog = document.getElementById('address-delete-confirm');
			if (target.hasAttribute('data-address-delete') && addressDialog && typeof addressDialog.showModal === 'function') {
				event.preventDefault();
				if (!addressDialog.open) {
					addressDialog.returnValue = '';
					addressDialog.addEventListener('close', function() {
						if (addressDialog.returnValue === 'delete') {
							window.location.assign(target.href);
						} else if (target.isConnected) {
							target.focus({preventScroll: true});
						}
					}, {once: true});
					addressDialog.showModal();
				}
				return;
			}
			if (!window.confirm(target.getAttribute('data-confirm'))) {
				event.preventDefault();
				return;
			}
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
		var alert = $('<div class="alert alert-danger alert-dismissible"><img class="atelier-icon atelier-icon--alert" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/alert.svg" alt="" aria-hidden="true"> <span></span><button type="button" class="close" data-dismiss="alert">&times;</button></div>');
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
		var nav = document.getElementById('site-nav');
		var navButton = document.querySelector('.mobile-nav-toggle');
		var backdrop = document.querySelector('.site-nav-backdrop');
		if (!navButton || !nav || !backdrop) {
			return;
		}

		var closeButton = nav.querySelector('.site-nav__close');
		// The default theme still uses the trigger itself to close the menu.
		var toggleIcon = !closeButton && navButton.querySelector('img.site-action-icon');

		function isOpen() {
			return nav.classList.contains('is-open');
		}

		function setOpen(open, focusTarget) {
			nav.classList.toggle('is-open', open);
			navButton.setAttribute('aria-expanded', open ? 'true' : 'false');
			navButton.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
			backdrop.hidden = !open;

			if (toggleIcon) {
				var iconName = open ? 'close' : 'menu';
				toggleIcon.setAttribute('src', 'catalog/view/theme/charm_by_sylora/image/icons/atelier/' + iconName + '.svg');
				toggleIcon.classList.toggle('atelier-icon--menu', !open);
				toggleIcon.classList.toggle('atelier-icon--close', open);
			}

			if (focusTarget) {
				focusTarget.focus({ preventScroll: true });
			}
		}

		function closeNav() {
			if (isOpen()) {
				setOpen(false, navButton);
			}
		}

		function closeOnDesktop() {
			if (isOpen() && navButton.getClientRects().length === 0) {
				var activeElement = document.activeElement;
				var focusTarget = nav.contains(activeElement) || activeElement === navButton
					? nav.querySelector('a[href]')
					: null;
				setOpen(false, focusTarget);
			}
		}

		navButton.addEventListener('click', function() {
			if (isOpen()) {
				closeNav();
			} else {
				setOpen(true, closeButton);
			}
		});
		if (closeButton) {
			closeButton.addEventListener('click', closeNav);
		}
		backdrop.addEventListener('click', closeNav);
		nav.addEventListener('click', function(event) {
			if (isOpen() && event.target.closest('a[href]')) {
				closeNav();
			}
		});

		window.addEventListener('resize', closeOnDesktop);
		new MutationObserver(closeOnDesktop).observe(nav.parentElement, {
			attributes: true,
			attributeFilter: ['class']
		});

		document.addEventListener('keydown', function(event) {
			if (!isOpen()) {
				return;
			}

			if (event.key === 'Escape') {
				event.preventDefault();
				closeNav();
				return;
			}

			if (event.key !== 'Tab') {
				return;
			}

			var links = nav.querySelectorAll('a[href]');
			var firstControl = closeButton || links[0];
			var lastControl = closeButton ? links[links.length - 1] : navButton;
			var activeElement = document.activeElement;
			var focusOutsidePanel = closeButton && !nav.contains(activeElement);

			if (event.shiftKey && (activeElement === firstControl || focusOutsidePanel)) {
				event.preventDefault();
				lastControl.focus();
			} else if (!event.shiftKey && (activeElement === lastControl || focusOutsidePanel)) {
				event.preventDefault();
				firstControl.focus();
			}
		});
	}

	function initializeHeaderSearch() {
		var searchContainer = document.querySelector('.site-search');
		var input = document.querySelector('.site-search #search input[name="search"]');
		var button = document.querySelector('.site-search #search button');
		var desktopSearch = window.matchMedia('(min-width: 1201px)');

		if (!searchContainer || !input || !button) {
			return;
		}

		function setOpen(isOpen, focusInput) {
			searchContainer.classList.toggle('is-open', isOpen);
			button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

			if (isOpen && focusInput) {
				input.focus();
			}
		}

		document.addEventListener('click', function(event) {
			var target = event.target;
			var searchButton = target.closest ? target.closest('.site-search #search button') : null;

			if (searchButton === button && desktopSearch.matches) {
				if (!searchContainer.classList.contains('is-open') || !input.value.trim()) {
					event.preventDefault();
					event.stopImmediatePropagation();
					setOpen(true, true);
				}

				return;
			}

			if (!searchContainer.contains(target) && searchContainer.classList.contains('is-open')) {
				setOpen(false, false);
			}
		}, true);

		document.addEventListener('keydown', function(event) {
			if (event.key === 'Escape' && desktopSearch.matches && searchContainer.classList.contains('is-open')) {
				setOpen(false, false);
				button.focus();
			}
		});

		if (typeof desktopSearch.addEventListener === 'function') {
			desktopSearch.addEventListener('change', function(event) {
				if (!event.matches) {
					setOpen(false, false);
				}
			});
		} else if (typeof desktopSearch.addListener === 'function') {
			desktopSearch.addListener(function(event) {
				if (!event.matches) {
					setOpen(false, false);
				}
			});
		}
	}

	function initializeCatalog() {
		var lastQuickViewTrigger = null;
		var filterDetails = document.querySelector('.catalog-filters');
		if (filterDetails) {
			var desktopFilters = window.matchMedia('(min-width: 992px)');
			var filterSummary = filterDetails.querySelector('.catalog-filters__summary');
			var closeFiltersButton = filterDetails.querySelector('.catalog-filters__close');

			function closeFilters() {
				filterDetails.open = false;
				filterSummary.focus();
			}

			function syncFilterDisclosure() {
				var focused = document.activeElement;
				filterDetails.open = desktopFilters.matches;

				if (!desktopFilters.matches && filterDetails.contains(focused)) {
					filterSummary.focus();
				} else if (desktopFilters.matches && (focused === filterSummary || focused === closeFiltersButton)) {
					filterDetails.querySelector('input[name="path"]:checked').focus();
				}
			}

			filterDetails.classList.add('catalog-filters--enhanced');
			syncFilterDisclosure();
			closeFiltersButton.addEventListener('click', closeFilters);
			filterDetails.addEventListener('toggle', function () {
				if (!desktopFilters.matches && filterDetails.open) {
					closeFiltersButton.focus();
				}
			});
			filterDetails.addEventListener('keydown', function(event) {
				if (event.key === 'Escape' && !desktopFilters.matches && filterDetails.open) {
					event.preventDefault();
					closeFilters();
				}
			});

			if (typeof desktopFilters.addEventListener === 'function') {
				desktopFilters.addEventListener('change', syncFilterDisclosure);
			} else if (typeof desktopFilters.addListener === 'function') {
				desktopFilters.addListener(syncFilterDisclosure);
			}
		}

		document.addEventListener('click', function(event) {
			var trigger = event.target.closest ? event.target.closest('.js-quick-view-open') : null;

			if (!trigger) {
				return;
			}

			var dialog = document.getElementById(trigger.getAttribute('data-dialog-id'));

			if (!dialog || dialog.open || typeof dialog.showModal !== 'function') {
				return;
			}

			dialog.showModal();
			dialog.scrollTop = 0;
			lastQuickViewTrigger = trigger;
			trigger.setAttribute('aria-expanded', 'true');
		});
		document.addEventListener('close', function(event) {
			if (!lastQuickViewTrigger || event.target.id !== lastQuickViewTrigger.getAttribute('data-dialog-id') || event.target.open) {
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
		initializeHeaderSearch();
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
