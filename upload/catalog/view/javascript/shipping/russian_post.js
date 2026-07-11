'use strict';
(function($) {
    var loading = false;

    function showWidget() {
        if (loading || $('#russian-post-widget').length) return;
        loading = true;
        $.getJSON('index.php?route=extension/shipping/russian_post/params').done(function(params) {
            if (params.error) throw new Error(params.error);
            var box = $('<div id="russian-post-widget-wrap"><div class="alert alert-danger" style="display:none"></div><div id="russian-post-widget" style="height:500px;max-height:70vh"></div></div>');
            $('input[value="russian_post.office"]').closest('.radio').after(box);
            function start() {
                window.ecomStartWidget($.extend({}, params, {
                    callbackFunction: function(selection) {
                        $.post('index.php?route=extension/shipping/russian_post/select', {selection: JSON.stringify(selection)}, function(result) {
                            if (result.error) {
                                box.find('.alert').text(result.error).show();
                                return;
                            }
                            $.get('index.php?route=checkout/shipping_method', function(html) {
                                $('#collapse-shipping-method .panel-body').html(html);
                            });
                        }, 'json');
                    },
                    containerId: 'russian-post-widget'
                }));
            }
            if (window.ecomStartWidget) {
                start();
            } else {
                $.getScript('https://widget.pochta.ru/map/widget/widget.js').done(start).fail(function() {
                    box.find('.alert').text('Не удалось загрузить карту Почты России.').show();
                });
            }
            loading = false;
        }).fail(function() {
            loading = false;
        });
    }

    function bind() {
        var input = $('input[value="russian_post.office"]');
        if (!input.length) return;
        if (input.is(':checked')) showWidget();
        input.off('change.russianPost').on('change.russianPost', showWidget);
    }

    $(document).on('ajaxComplete', bind);
    $(bind);
})(jQuery);
