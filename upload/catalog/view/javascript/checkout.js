(function(window, document, $) {
'use strict';

var configElement = document.getElementById('checkout-config');
var config = configElement ? JSON.parse(configElement.textContent || '{}') : {};

var summary = $('#checkout-summary-data');
var summaryRetry = $('#checkout-summary-retry');
var summaryRequest = null;
var summaryGeneration = 0;
var summaryChanges = 0;
var shippingPending = false;
var shippingMethodsRequest = null;

function summaryStatus(message, busy, retry) {
	summary.empty().text(message).attr('aria-busy', busy ? 'true' : 'false');
	summaryRetry.prop('hidden', !retry);
}

function invalidateSummary(message, busy) {
	summaryGeneration++;
	if (summaryRequest) {
		summaryRequest.abort();
		summaryRequest = null;
	}
	summaryStatus(message, busy, false);
}

function refreshSummary() {
	if (!summary.length || summaryChanges) return;
	if (shippingPending) {
		summaryStatus(config.summaryPending, false, false);
		return;
	}
	invalidateSummary(config.summaryLoading, true);
	var generation = summaryGeneration;
	summaryRequest = $.ajax({url: 'index.php?route=common/cart/info', dataType: 'html'});
	summaryRequest.done(function(html) {
		if (generation !== summaryGeneration) return;
		var doc = new DOMParser().parseFromString(html, 'text/html');
		var cart = doc.querySelector('#cart');
		if (!cart) {
			summaryStatus(config.summaryError, false, true);
			return;
		}
		var items = cart.querySelector('.mini-cart__items');
		var totals = cart.querySelector('.mini-cart__summary table');
		var empty = cart.querySelector('.mini-cart__empty p');
		summary.empty();
		if (items) {
			items.querySelectorAll('button').forEach(function(button) { button.remove(); });
			summary.append(items);
		}
		if (totals) summary.append(totals);
		if (empty) summary.append(empty);
		if (!items && !totals && !empty) {
			summaryStatus(config.summaryError, false, true);
			return;
		}
		summary.attr('aria-busy', 'false');
	});
	summaryRequest.fail(function(xhr, status) {
		if (status !== 'abort' && generation === summaryGeneration) summaryStatus(config.summaryError, false, true);
	});
}

function changesSummary(settings) {
	return /[?&]route=checkout\/(?:[^&]*\/save|confirm|cart\/(?:add|edit|remove))(?:&|$)/.test(settings.url || '');
}

function showShippingMethodsStep(step) {
	if (!step.is('.in, .collapsing')) {
		var title = step.parent().find('.panel-heading .panel-title');
		title.html('<a href="#collapse-shipping-method" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutShippingMethod + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');
		title.find('a').trigger('click');
	}
	$('#collapse-payment-method').parent().find('.panel-heading .panel-title').text(config.textCheckoutPaymentMethod);
	$('#collapse-checkout-confirm').parent().find('.panel-heading .panel-title').text(config.textCheckoutConfirm);
}

function loadShippingMethods(options) {
	if (shippingMethodsRequest) shippingMethodsRequest.abort();

	var step = $('#collapse-shipping-method');
	var body = step.children('.panel-body');
	var loading = document.getElementById('checkout-shipping-loading');
	body.empty().append(loading.content.cloneNode(true));
	showShippingMethodsStep(step);

	shippingMethodsRequest = $.ajax({
		url: 'index.php?route=checkout/shipping_method',
		dataType: 'html',
		complete: function() {
			shippingMethodsRequest = null;
			if (options.complete) options.complete();
		},
		success: function(html) {
			body.html(html);
			if (options.success) options.success();
		},
		error: function(xhr, status) {
			if (status === 'abort') return;
			var content = document.getElementById('checkout-shipping-load-error').content.cloneNode(true);
			var retry = content.querySelector('[data-shipping-load-retry]');
			var changeAddress = content.querySelector('a');
			if (!$('#collapse-shipping-address .panel-body').children().length) {
				changeAddress.setAttribute('href', '#collapse-payment-address');
			}
			retry.addEventListener('click', function() {
				loadShippingMethods(options);
			});
			body.empty().append(content);
		}
	});
}

var cdekOfficeSaveRoute = /[?&]route=extension\/shipping\/cdek_official\/cacheOfficeCode(?:&|$)/;

$(document).on('change', '#collapse-shipping-method input[name="shipping_method"]', function() {
	$(this).closest('#collapse-shipping-method').find('[data-shipping-save-error]').remove();
	if (!summary.length) return;
	shippingPending = true;
	invalidateSummary(config.summaryPending, false);
});

$(document).on('ajaxSuccess', function(event, xhr, settings) {
	if (!cdekOfficeSaveRoute.test(settings.url || '') || xhr.responseText !== 'Ok') return;
	$('#collapse-shipping-method input[name="shipping_method"][value^="cdek_official."]:checked')
		.closest('#collapse-shipping-method').find('[data-shipping-save-error]').remove();
});

if (summary.length) {
	$(document).on('ajaxSend', function(event, xhr, settings) {
		if (cdekOfficeSaveRoute.test(settings.url || '') || /[?&]route=extension\/shipping\/russian_post\/(?:select|reset)(?:&|$)/.test(settings.url || '')) {
			shippingPending = true;
			invalidateSummary(config.summaryPending, false);
		}
		if (!changesSummary(settings)) return;
		summaryChanges++;
		invalidateSummary(config.summaryLoading, true);
	});
	$(document).on('ajaxComplete', function(event, xhr, settings) {
		if (!changesSummary(settings)) return;
		summaryChanges = Math.max(0, summaryChanges - 1);
		if (/[?&]route=checkout\/shipping_method\/save(?:&|$)/.test(settings.url || '') && xhr.status === 200 && xhr.responseJSON && !xhr.responseJSON.error && !xhr.responseJSON.redirect) shippingPending = false;
		refreshSummary();
	});
	summaryRetry.on('click', refreshSummary);
	$(refreshSummary);
}

$(document).on('click', '#button-buyer-continue, #button-buyer-login', function(event) {
	if (this.id === 'button-buyer-login') {
		event.preventDefault();
	}

	var buyer = $('#checkout-buyer');
	var options = $('#checkout-buyer-options');

	if (!buyer.length || !options.length) return;

	buyer.addClass('checkout-buyer--expanded');
	options.prop('hidden', false);
	$('#button-buyer-continue, #button-buyer-login').attr('aria-expanded', 'true');
	$('#button-buyer-continue').prop('hidden', true);

	if (this.id === 'button-buyer-login') {
		$('#input-password').trigger('focus');
	}
});

$(document).on('change', 'input[name=\'account\']', function() {
	if ($('#collapse-payment-address').parent().find('.panel-heading .panel-title > *').is('a')) {
		if (this.value == 'register') {
			$('#collapse-payment-address').parent().find('.panel-heading .panel-title').html('<a href="#collapse-payment-address" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutAccount + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');
		} else {
			$('#collapse-payment-address').parent().find('.panel-heading .panel-title').html('<a href="#collapse-payment-address" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutPaymentAddress + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');
		}
	} else {
		if (this.value == 'register') {
			$('#collapse-payment-address').parent().find('.panel-heading .panel-title').html('' + config.textCheckoutAccount + '');
		} else {
			$('#collapse-payment-address').parent().find('.panel-heading .panel-title').html('' + config.textCheckoutPaymentAddress + '');
		}
	}
});

if (!config.logged) {
$(document).ready(function() {
    $.ajax({
        url: 'index.php?route=checkout/login',
        dataType: 'html',
        success: function(html) {
           $('#collapse-checkout-option .panel-body').html(html);

			$('#collapse-checkout-option').parent().find('.panel-heading .panel-title').html('<a href="#collapse-checkout-option" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutOption + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');

			$('a[href=\'#collapse-checkout-option\']').trigger('click');
        },
        error: function(xhr, ajaxOptions, thrownError) {
            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
        }
    });
});
} else {
$(document).ready(function() {
    $.ajax({
        url: 'index.php?route=checkout/payment_address',
        dataType: 'html',
        success: function(html) {
            $('#collapse-payment-address .panel-body').html(html);

			$('#collapse-payment-address').parent().find('.panel-heading .panel-title').html('<a href="#collapse-payment-address" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutPaymentAddress + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');

			$('a[href=\'#collapse-payment-address\']').trigger('click');
        },
        error: function(xhr, ajaxOptions, thrownError) {
            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
        }
    });
});
}

// Checkout
$(document).delegate('#button-account', 'click', function() {
	var accountEmail = $('#input-email').val() || '';

    $.ajax({
        url: 'index.php?route=checkout/' + $('input[name=\'account\']:checked').val(),
        dataType: 'html',
        beforeSend: function() {
		$('#button-account').button('loading');
		},
        complete: function() {
			$('#button-account').button('reset');
        },
        success: function(html) {
            $('.alert-dismissible, .text-danger').remove();
			$('.form-group').removeClass('has-error');

            $('#collapse-payment-address .panel-body').html(html);
			if (accountEmail !== '') {
				$('#collapse-payment-address .panel-body #input-payment-email').val(accountEmail);
			}

			if ($('input[name=\'account\']:checked').val() == 'register') {
				$('#collapse-payment-address').parent().find('.panel-heading .panel-title').html('<a href="#collapse-payment-address" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutAccount + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');
			} else {
				$('#collapse-payment-address').parent().find('.panel-heading .panel-title').html('<a href="#collapse-payment-address" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutPaymentAddress + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');
			}

			$('a[href=\'#collapse-payment-address\']').trigger('click');
        },
        error: function(xhr, ajaxOptions, thrownError) {
            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
        }
    });
});

// Login
$(document).delegate('#button-login', 'click', function() {
    $.ajax({
        url: 'index.php?route=checkout/login/save',
        type: 'post',
        data: $('#collapse-checkout-option :input'),
        dataType: 'json',
        beforeSend: function() {
		$('#button-login').button('loading');
		},
        complete: function() {
            $('#button-login').button('reset');
        },
        success: function(json) {
            $('.alert-dismissible, .text-danger').remove();
            $('.form-group').removeClass('has-error');

            if (json['redirect']) {
                location = json['redirect'];
            } else if (json['error']) {
                $('#collapse-checkout-option .panel-body').prepend('<div class="alert alert-danger alert-dismissible"><img class="atelier-icon atelier-icon--alert" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/alert.svg" alt="" aria-hidden="true"> ' + json['error']['warning'] + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');

				// Highlight any found errors
				$('input[name=\'email\']').parent().addClass('has-error');
				$('input[name=\'password\']').parent().addClass('has-error');
		   }
        },
        error: function(xhr, ajaxOptions, thrownError) {
            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
        }
    });
});

// Register
$(document).delegate('#button-register', 'click', function() {
    $.ajax({
        url: 'index.php?route=checkout/register/save',
        type: 'post',
        data: $('#collapse-payment-address input[type=\'text\'], #collapse-payment-address input[type=\'date\'], #collapse-payment-address input[type=\'datetime-local\'], #collapse-payment-address input[type=\'time\'], #collapse-payment-address input[type=\'password\'], #collapse-payment-address input[type=\'hidden\'], #collapse-payment-address input[type=\'checkbox\']:checked, #collapse-payment-address input[type=\'radio\']:checked, #collapse-payment-address textarea, #collapse-payment-address select'),
        dataType: 'json',
        beforeSend: function() {
			$('#button-register').button('loading');
		},
        success: function(json) {
            $('.alert-dismissible, .text-danger').remove();
            $('.form-group').removeClass('has-error');

            if (json['redirect']) {
                location = json['redirect'];
            } else if (json['error']) {
                $('#button-register').button('reset');

                if (json['error']['warning']) {
                    $('#collapse-payment-address .panel-body').prepend('<div class="alert alert-danger alert-dismissible"><img class="atelier-icon atelier-icon--alert" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/alert.svg" alt="" aria-hidden="true"> ' + json['error']['warning'] + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
                }

				for (var i in json['error']) {
					var element = $('#input-payment-' + i.replace('_', '-'));

					if ($(element).parent().hasClass('input-group')) {
						$(element).parent().after('<div class="text-danger">' + json['error'][i] + '</div>');
					} else {
						$(element).after('<div class="text-danger">' + json['error'][i] + '</div>');
					}
				}

				// Highlight any found errors
				$('.text-danger').parent().addClass('has-error');
            } else {
                if (config.shippingRequired) {
                var shipping_address = $('#collapse-payment-address input[name=\'shipping_address\']:checked').prop('value');

                if (shipping_address) {
                    loadShippingMethods({
                        success: function() {
							// Add the shipping address
                            $.ajax({
                                url: 'index.php?route=checkout/shipping_address',
                                dataType: 'html',
                                success: function(html) {
                                    $('#collapse-shipping-address .panel-body').html(html);

									$('#collapse-shipping-address').parent().find('.panel-heading .panel-title').html('<a href="#collapse-shipping-address" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutShippingAddress + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');
                                },
                                error: function(xhr, ajaxOptions, thrownError) {
                                    alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                                }
                            });
							$('#collapse-shipping-method').parent().find('.panel-heading .panel-title').text(config.textCheckoutShippingMethod);
                        }
                    });
                } else {
                    $.ajax({
                        url: 'index.php?route=checkout/shipping_address',
                        dataType: 'html',
                        success: function(html) {
                            $('#collapse-shipping-address .panel-body').html(html);

							$('#collapse-shipping-address').parent().find('.panel-heading .panel-title').html('<a href="#collapse-shipping-address" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutShippingAddress + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');

							$('a[href=\'#collapse-shipping-address\']').trigger('click');

							$('#collapse-shipping-method').parent().find('.panel-heading .panel-title').html('' + config.textCheckoutShippingMethod + '');
							$('#collapse-payment-method').parent().find('.panel-heading .panel-title').html('' + config.textCheckoutPaymentMethod + '');
							$('#collapse-checkout-confirm').parent().find('.panel-heading .panel-title').html('' + config.textCheckoutConfirm + '');
                        },
                        error: function(xhr, ajaxOptions, thrownError) {
                            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                        }
                    });
                }
                } else {
                $.ajax({
                    url: 'index.php?route=checkout/payment_method',
                    dataType: 'html',
                    success: function(html) {
                        $('#collapse-payment-method .panel-body').html(html);

						$('#collapse-payment-method').parent().find('.panel-heading .panel-title').html('<a href="#collapse-payment-method" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutPaymentMethod + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');

						$('a[href=\'#collapse-payment-method\']').trigger('click');

						$('#collapse-checkout-confirm').parent().find('.panel-heading .panel-title').html('' + config.textCheckoutConfirm + '');
                    },
                    error: function(xhr, ajaxOptions, thrownError) {
                        alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                    }
                });
                }

                $.ajax({
                    url: 'index.php?route=checkout/payment_address',
                    dataType: 'html',
                    complete: function() {
                        $('#button-register').button('reset');
                    },
                    success: function(html) {
                        $('#collapse-payment-address .panel-body').html(html);

						$('#collapse-payment-address').parent().find('.panel-heading .panel-title').html('<a href="#collapse-payment-address" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutPaymentAddress + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');
                    },
                    error: function(xhr, ajaxOptions, thrownError) {
                        alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                    }
                });
            }
        },
        error: function(xhr, ajaxOptions, thrownError) {
            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
        }
    });
});

// Payment Address
$(document).delegate('#button-payment-address', 'click', function() {
    $.ajax({
        url: 'index.php?route=checkout/payment_address/save',
        type: 'post',
        data: $('#collapse-payment-address input[type=\'text\'], #collapse-payment-address input[type=\'date\'], #collapse-payment-address input[type=\'datetime-local\'], #collapse-payment-address input[type=\'time\'], #collapse-payment-address input[type=\'password\'], #collapse-payment-address input[type=\'checkbox\']:checked, #collapse-payment-address input[type=\'radio\']:checked, #collapse-payment-address input[type=\'hidden\'], #collapse-payment-address textarea, #collapse-payment-address select'),
        dataType: 'json',
        beforeSend: function() {
		$('#button-payment-address').button('loading');
		},
        complete: function() {
			$('#button-payment-address').button('reset');
        },
        success: function(json) {
            $('.alert-dismissible, .text-danger').remove();
			$('.form-group').removeClass('has-error');

            if (json['redirect']) {
                location = json['redirect'];
            } else if (json['error']) {
                if (json['error']['warning']) {
                    $('#collapse-payment-address .panel-body').prepend('<div class="alert alert-warning alert-dismissible">' + json['error']['warning'] + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
                }

				for (var i in json['error']) {
					var element = $('#input-payment-' + i.replace('_', '-'));

					if ($(element).parent().hasClass('input-group')) {
						$(element).parent().after('<div class="text-danger">' + json['error'][i] + '</div>');
					} else {
						$(element).after('<div class="text-danger">' + json['error'][i] + '</div>');
					}
				}

				// Highlight any found errors
				$('.text-danger').parent().parent().addClass('has-error');
            } else {
                if (config.shippingRequired) {
                $.ajax({
                    url: 'index.php?route=checkout/shipping_address',
                    dataType: 'html',
                    success: function(html) {
                        $('#collapse-shipping-address .panel-body').html(html);

						$('#collapse-shipping-address').parent().find('.panel-heading .panel-title').html('<a href="#collapse-shipping-address" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutShippingAddress + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');

						$('a[href=\'#collapse-shipping-address\']').trigger('click');

						$('#collapse-shipping-method').parent().find('.panel-heading .panel-title').html('' + config.textCheckoutShippingMethod + '');
						$('#collapse-payment-method').parent().find('.panel-heading .panel-title').html('' + config.textCheckoutPaymentMethod + '');
						$('#collapse-checkout-confirm').parent().find('.panel-heading .panel-title').html('' + config.textCheckoutConfirm + '');
                    },
                    error: function(xhr, ajaxOptions, thrownError) {
                        alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                    }
                }).done(function() {
					$.ajax({
						url: 'index.php?route=checkout/payment_address',
						dataType: 'html',
						success: function(html) {
							$('#collapse-payment-address .panel-body').html(html);
						},
						error: function(xhr, ajaxOptions, thrownError) {
							alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
						}
					});
				});
                } else {
                $.ajax({
                    url: 'index.php?route=checkout/payment_method',
                    dataType: 'html',
                    success: function(html) {
                        $('#collapse-payment-method .panel-body').html(html);

						$('#collapse-payment-method').parent().find('.panel-heading .panel-title').html('<a href="#collapse-payment-method" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutPaymentMethod + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');

						$('a[href=\'#collapse-payment-method\']').trigger('click');

						$('#collapse-checkout-confirm').parent().find('.panel-heading .panel-title').html('' + config.textCheckoutConfirm + '');
                    },
                    error: function(xhr, ajaxOptions, thrownError) {
                        alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                    }
                }).done(function() {
					$.ajax({
						url: 'index.php?route=checkout/payment_address',
						dataType: 'html',
						success: function(html) {
							$('#collapse-payment-address .panel-body').html(html);
						},
						error: function(xhr, ajaxOptions, thrownError) {
							alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
						}
					});
				});
                }
            }
        },
        error: function(xhr, ajaxOptions, thrownError) {
            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
        }
    });
});

// Shipping Address
$(document).delegate('#button-shipping-address', 'click', function() {
    $.ajax({
        url: 'index.php?route=checkout/shipping_address/save',
        type: 'post',
        data: $('#collapse-shipping-address input[type=\'text\'], #collapse-shipping-address input[type=\'date\'], #collapse-shipping-address input[type=\'datetime-local\'], #collapse-shipping-address input[type=\'time\'], #collapse-shipping-address input[type=\'password\'], #collapse-shipping-address input[type=\'checkbox\']:checked, #collapse-shipping-address input[type=\'radio\']:checked, #collapse-shipping-address textarea, #collapse-shipping-address select'),
        dataType: 'json',
        beforeSend: function() {
			$('#button-shipping-address').button('loading');
	    },
        success: function(json) {
            $('.alert-dismissible, .text-danger').remove();
			$('.form-group').removeClass('has-error');

            if (json['redirect']) {
                location = json['redirect'];
            } else if (json['error']) {
                $('#button-shipping-address').button('reset');

                if (json['error']['warning']) {
                    $('#collapse-shipping-address .panel-body').prepend('<div class="alert alert-warning alert-dismissible">' + json['error']['warning'] + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
                }

				for (var i in json['error']) {
					var element = $('#input-shipping-' + i.replace('_', '-'));

					if ($(element).parent().hasClass('input-group')) {
						$(element).parent().after('<div class="text-danger">' + json['error'][i] + '</div>');
					} else {
						$(element).after('<div class="text-danger">' + json['error'][i] + '</div>');
					}
				}

				// Highlight any found errors
				$('.text-danger').parent().parent().addClass('has-error');
            } else {
                loadShippingMethods({
                    complete: function() {
                        $('#button-shipping-address').button('reset');
                    },
                    success: function() {
                        $.ajax({
                            url: 'index.php?route=checkout/shipping_address',
                            dataType: 'html',
                            success: function(html) {
                                $('#collapse-shipping-address .panel-body').html(html);
                            },
                            error: function(xhr, ajaxOptions, thrownError) {
                                alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                            }
                        });

						$.ajax({
							url: 'index.php?route=checkout/payment_address',
							dataType: 'html',
							success: function(html) {
								$('#collapse-payment-address .panel-body').html(html);
							},
							error: function(xhr, ajaxOptions, thrownError) {
								alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
							}
						});
                    }
                });
            }
        },
        error: function(xhr, ajaxOptions, thrownError) {
            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
        }
    });
});

// Guest
$(document).delegate('#button-guest', 'click', function() {
    $.ajax({
        url: 'index.php?route=checkout/guest/save',
        type: 'post',
        data: $('#collapse-payment-address input[type=\'text\'], #collapse-payment-address input[type=\'date\'], #collapse-payment-address input[type=\'datetime-local\'], #collapse-payment-address input[type=\'time\'], #collapse-payment-address input[type=\'checkbox\']:checked, #collapse-payment-address input[type=\'radio\']:checked, #collapse-payment-address input[type=\'hidden\'], #collapse-payment-address textarea, #collapse-payment-address select'),
        dataType: 'json',
        beforeSend: function() {
			$('#button-guest').button('loading');
	    },
        success: function(json) {
            $('.alert-dismissible, .text-danger').remove();
			$('.form-group').removeClass('has-error');

            if (json['redirect']) {
                location = json['redirect'];
            } else if (json['error']) {
                $('#button-guest').button('reset');

                if (json['error']['warning']) {
                    $('#collapse-payment-address .panel-body').prepend('<div class="alert alert-warning alert-dismissible">' + json['error']['warning'] + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
                }

				for (var i in json['error']) {
					var element = $('#input-payment-' + i.replace('_', '-'));

					if ($(element).parent().hasClass('input-group')) {
						$(element).parent().after('<div class="text-danger">' + json['error'][i] + '</div>');
					} else {
						$(element).after('<div class="text-danger">' + json['error'][i] + '</div>');
					}
				}

				// Highlight any found errors
				$('.text-danger').parent().addClass('has-error');
            } else {
                if (config.shippingRequired) {
                var shipping_address = $('#collapse-payment-address input[name=\'shipping_address\']:checked').prop('value');

                if (shipping_address) {
                    loadShippingMethods({
                        complete: function() {
                            $('#button-guest').button('reset');
                        },
                        success: function() {
							// Add the shipping address
                            $.ajax({
                                url: 'index.php?route=checkout/guest_shipping',
                                dataType: 'html',
                                success: function(html) {
                                    $('#collapse-shipping-address .panel-body').html(html);

									$('#collapse-shipping-address').parent().find('.panel-heading .panel-title').html('<a href="#collapse-shipping-address" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutShippingAddress + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');
                                },
                                error: function(xhr, ajaxOptions, thrownError) {
                                    alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                                }
                            });
                        }
                    });
                } else {
                    $.ajax({
                        url: 'index.php?route=checkout/guest_shipping',
                        dataType: 'html',
                        complete: function() {
                            $('#button-guest').button('reset');
                        },
                        success: function(html) {
                            $('#collapse-shipping-address .panel-body').html(html);

							$('#collapse-shipping-address').parent().find('.panel-heading .panel-title').html('<a href="#collapse-shipping-address" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutShippingAddress + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');

							$('a[href=\'#collapse-shipping-address\']').trigger('click');

							$('#collapse-shipping-method').parent().find('.panel-heading .panel-title').html('' + config.textCheckoutShippingMethod + '');
							$('#collapse-payment-method').parent().find('.panel-heading .panel-title').html('' + config.textCheckoutPaymentMethod + '');
							$('#collapse-checkout-confirm').parent().find('.panel-heading .panel-title').html('' + config.textCheckoutConfirm + '');
                        },
                        error: function(xhr, ajaxOptions, thrownError) {
                            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                        }
                    });
                }
                } else {
                $.ajax({
                    url: 'index.php?route=checkout/payment_method',
                    dataType: 'html',
                    complete: function() {
                        $('#button-guest').button('reset');
                    },
                    success: function(html) {
                        $('#collapse-payment-method .panel-body').html(html);

						$('#collapse-payment-method').parent().find('.panel-heading .panel-title').html('<a href="#collapse-payment-method" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutPaymentMethod + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');

						$('a[href=\'#collapse-payment-method\']').trigger('click');

						$('#collapse-checkout-confirm').parent().find('.panel-heading .panel-title').html('' + config.textCheckoutConfirm + '');
                    },
                    error: function(xhr, ajaxOptions, thrownError) {
                        alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                    }
                });
                }
            }
        },
        error: function(xhr, ajaxOptions, thrownError) {
            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
        }
    });
});

// Guest Shipping
$(document).delegate('#button-guest-shipping', 'click', function() {
    $.ajax({
        url: 'index.php?route=checkout/guest_shipping/save',
        type: 'post',
        data: $('#collapse-shipping-address input[type=\'text\'], #collapse-shipping-address input[type=\'date\'], #collapse-shipping-address input[type=\'datetime-local\'], #collapse-shipping-address input[type=\'time\'], #collapse-shipping-address input[type=\'password\'], #collapse-shipping-address input[type=\'checkbox\']:checked, #collapse-shipping-address input[type=\'radio\']:checked, #collapse-shipping-address textarea, #collapse-shipping-address select'),
        dataType: 'json',
        beforeSend: function() {
		$('#button-guest-shipping').button('loading');
		},
        success: function(json) {
            $('.alert-dismissible, .text-danger').remove();
			$('.form-group').removeClass('has-error');

            if (json['redirect']) {
                location = json['redirect'];
            } else if (json['error']) {
                $('#button-guest-shipping').button('reset');

                if (json['error']['warning']) {
                    $('#collapse-shipping-address .panel-body').prepend('<div class="alert alert-danger alert-dismissible">' + json['error']['warning'] + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
                }

				for (var i in json['error']) {
					var element = $('#input-shipping-' + i.replace('_', '-'));

					if ($(element).parent().hasClass('input-group')) {
						$(element).parent().after('<div class="text-danger">' + json['error'][i] + '</div>');
					} else {
						$(element).after('<div class="text-danger">' + json['error'][i] + '</div>');
					}
				}

				// Highlight any found errors
				$('.text-danger').parent().parent().addClass('has-error');
            } else {
                loadShippingMethods({
                    complete: function() {
                        $('#button-guest-shipping').button('reset');
                    }
                });
            }
        },
        error: function(xhr, ajaxOptions, thrownError) {
            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
        }
    });
});

$(document).delegate('#button-shipping-method', 'click', function() {
    var button = $(this);
    var shippingStep = $('#collapse-shipping-method');
    var saveStatus = shippingStep.find('[data-shipping-save-status]');
    var selectedMethod = shippingStep.find('input[name="shipping_method"]:checked');

    $.ajax({
        url: 'index.php?route=checkout/shipping_method/save',
        type: 'post',
        data: $('#collapse-shipping-method input[type=\'radio\']:checked, #collapse-shipping-method textarea'),
        dataType: 'json',
        beforeSend: function() {
            shippingStep.find('[data-shipping-save-error]').remove();
            button.button('loading');
            saveStatus.prop('hidden', false);
        },
        complete: function() {
            saveStatus.prop('hidden', true);
        },
        success: function(json) {
            $('.alert-dismissible, .text-danger').remove();

            if (json['redirect']) {
                location = json['redirect'];
            } else if (json['error']) {
                button.button('reset');

                if (json['error']['warning']) {
                    if (selectedMethod.length && !selectedMethod.is(':checked')) return;

                    var warning = $('<div class="alert alert-danger alert-dismissible text-danger" role="alert" data-shipping-save-error></div>')
                        .text(json['error']['warning'])
                        .append('<button type="button" class="close" data-dismiss="alert">&times;</button>');
                    var card = selectedMethod.closest('.shipping-method-card');

                    if (card.length) {
                        card.after(warning);
                    } else {
                        shippingStep.find('.panel-body').prepend(warning);
                    }
                }
            } else {
                $.ajax({
                    url: 'index.php?route=checkout/payment_method',
                    dataType: 'html',
                    complete: function() {
                        button.button('reset');
                    },
                    success: function(html) {
                        $('#collapse-payment-method .panel-body').html(html);

						$('#collapse-payment-method').parent().find('.panel-heading .panel-title').html('<a href="#collapse-payment-method" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutPaymentMethod + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');

						$('a[href=\'#collapse-payment-method\']').trigger('click');

						$('#collapse-checkout-confirm').parent().find('.panel-heading .panel-title').html('' + config.textCheckoutConfirm + '');
                    },
                    error: function(xhr, ajaxOptions, thrownError) {
                        alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                    }
                });
            }
        },
        error: function(xhr, ajaxOptions, thrownError) {
            button.button('reset');
            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
        }
    });
});

$(document).delegate('#button-payment-method', 'click', function() {
    $.ajax({
        url: 'index.php?route=checkout/payment_method/save',
        type: 'post',
        data: $('#collapse-payment-method input[type=\'radio\']:checked, #collapse-payment-method input[type=\'checkbox\']:checked, #collapse-payment-method textarea'),
        dataType: 'json',
        beforeSend: function() {
		$('#button-payment-method').button('loading');
		},
        success: function(json) {
            $('.alert-dismissible, .text-danger').remove();

            if (json['redirect']) {
                location = json['redirect'];
            } else if (json['error']) {
                $('#button-payment-method').button('reset');

                if (json['error']['warning']) {
                    $('#collapse-payment-method .panel-body').prepend('<div class="alert alert-danger alert-dismissible">' + json['error']['warning'] + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
                }
            } else {
                $.ajax({
                    url: 'index.php?route=checkout/confirm',
                    dataType: 'html',
                    complete: function() {
                        $('#button-payment-method').button('reset');
                    },
                    success: function(html) {
                        $('#collapse-checkout-confirm .panel-body').html(html);

						$('#collapse-checkout-confirm').parent().find('.panel-heading .panel-title').html('<a href="#collapse-checkout-confirm" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">' + config.textCheckoutConfirm + ' <img class="atelier-icon atelier-icon--chevron-down" src="catalog/view/theme/charm_by_sylora/image/icons/atelier/chevron-down.svg" alt="" aria-hidden="true"></a>');

						$('a[href=\'#collapse-checkout-confirm\']').trigger('click');
					},
                    error: function(xhr, ajaxOptions, thrownError) {
                        alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                    }
                });
            }
        },
        error: function(xhr, ajaxOptions, thrownError) {
            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
        }
    });
});

})(window, document, window.jQuery);
