/* global jQuery, TheriusSDK, prestashop */
jQuery(document).ready(function($) {
    if (typeof prestashop === 'undefined') {
        return;
    }

    var therius = null;
    var widget = null;
    // Tracks the exact DOM node the widget is currently mounted into, so the
    // polling loop below never mounts twice into the same node (e.g. if the
    // widget's own re-render briefly leaves the container empty between
    // ticks) - it only remounts when PrestaShop genuinely replaces the
    // container with a fresh element (a new node with the same id).
    var mountedContainer = null;

    // Set right before the two deliberate, code-driven submits below (a
    // successful widget payment, or a completed 3DS/APM action). Guards
    // against PrestaShop's own shared "Place order" button (or Enter-key
    // submission) posting this form before any payment has actually run -
    // see the submit guard bound in init() below.
    var theriusPaymentConfirmed = false;

    function conditionsApproved() {
        var $boxes = $('#conditions-to-approve input[type="checkbox"]');
        return $boxes.length === 0 || $boxes.filter(':checked').length === $boxes.length;
    }

    function showStatus(text) {
        $('#therius-error-message').hide();
        $('#therius-status-message').text(text).show();
    }

    function showError(text) {
        $('#therius-status-message').hide();
        $('#therius-error-message').text(text).show();
    }

    // Forward clicks on PrestaShop's own "Place order" button into the
    // widget's submit flow via widget.submit() (therius-sdk's own public
    // method, not a DOM click on an internal button we have to locate or
    // hide ourselves - see checkoutOptions.hideSubmitButton in
    // mountWidget() below). This is a single capture-phase listener so it
    // runs before any theme-bound click handler already attached to that
    // button (jQuery handlers registered earlier in the page's lifecycle
    // would otherwise run first and can't be un-done from here) -
    // preventDefault + stopImmediatePropagation here reliably blocks them
    // regardless of registration order or exact theme markup.
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('#payment-confirmation button[type="submit"], #payment-confirmation button.btn');
        if (!btn) {
            return;
        }
        var isTherius = $('input[name="payment-option"]:checked').attr('data-module-name') === 'therius';
        if (!isTherius) {
            return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();

        if (widget) {
            widget.submit();
        } else {
            showError('Payment is still loading. Please wait a moment and try again.');
        }
    }, true);

    // Safety net: if this form is ever submitted without having gone through
    // a completed widget payment (some other click path, Enter key, etc.),
    // block it with a clear message instead of silently redirecting back to
    // checkout step 1.
    $('#therius-embedded-form').on('submit', function(e) {
        if (!theriusPaymentConfirmed) {
            e.preventDefault();
            showError('Please use the "Place order" button to complete your order.');
            $('html, body').animate({scrollTop: $('#therius-embedded-form').offset().top - 100}, 500);
        }
    });

    // A charge can succeed and then the order-creation POST below can still
    // get silently swallowed by some other script on the page calling
    // preventDefault() on the form's submit event - after real money has
    // already moved. Retries with the native (non-jQuery) submit(), which
    // does not fire the 'submit' event at all and so cannot be intercepted;
    // if it still hasn't navigated away after a few attempts, shows a
    // persistent message with the payment reference so the customer knows
    // not to pay again and has something to give support.
    function submitOrderForm(paymentCode) {
        var attempt = 0;
        var maxAttempts = 3;
        var form = document.getElementById('therius-embedded-form');

        function tryOnce() {
            attempt++;
            form.submit(); // native submit - bypasses the 'submit' event entirely
            if (attempt < maxAttempts) {
                window.setTimeout(function() {
                    // Still here means the page never navigated away.
                    tryOnce();
                }, attempt * 1500);
            } else {
                window.setTimeout(function() {
                    showError(
                        'Your payment was successful (reference: ' + paymentCode + ') but we could not finish ' +
                        'placing your order automatically. Please do NOT pay again - contact support with this ' +
                        'reference number.'
                    );
                }, 1500);
            }
        }

        tryOnce();
    }

    // Wait for TheriusSDK and the widget container to load asynchronously
    // PrestaShop 1.7+ dynamically injects the form when the user reaches the payment step!
    var sdkCheck = setInterval(function() {
        var $form = $('#therius-embedded-form');
        if (typeof TheriusSDK !== 'undefined' && $form.length > 0) {
            clearInterval(sdkCheck);

            window.theriusConfig = {
                clientTokenUrl: $form.attr('data-client-token-url'),
                preOrderUrl: $form.attr('data-pre-order-url'),
                finalizeUrl: $form.attr('data-finalize-url'),
                configId: $form.attr('data-config-id'),
                amount: parseInt($form.attr('data-amount'), 10) || 0
            };
            
            init();
        }
    }, 200);

    function init() {
        $.ajax({
            url: window.theriusConfig.clientTokenUrl,
            method: 'POST',
            dataType: 'json',
            success: function(response) {
                if (response.clientToken) {
                    mountWidget(response.clientToken, response.baseUrl || 'https://api.therius.io');
                } else if (response.error) {
                    console.error("Therius session error:", response.error);
                    $('#therius-error-message').text("Therius API Error: " + response.error).show();
                }
            },
            error: function(xhr, status, error) {
                console.error("Therius session request failed:", error);
                $('#therius-error-message').text("Payment initialization failed.").show();
            }
        });
    }

    function mountWidget(clientToken, baseUrl) {
        therius = new TheriusSDK.TheriusSDK({
            clientToken: clientToken,
            baseUrl: baseUrl
        });

        var checkoutOptions = {
            amount: window.theriusConfig.amount,
            currency: prestashop.currency.iso_code,
            // No hardcoded country here - the SDK overrides this from the
            // session's own country (therius-sdk/checkout.ts mount(): "session
            // values override caller-supplied options"), which purchase.php's
            // action=session now resolves from the customer's actual billing
            // address rather than PrestaShop's ambient browsing country.
            checkoutConfigId: window.theriusConfig.configId,
            // PrestaShop's own "Place order" button is the single visible
            // call-to-action - see the document click listener above, which
            // forwards to widget.submit() instead of clicking a DOM button.
            hideSubmitButton: true,

            onNonce: function(nonce, ddcSessionId, vaultConsent) {
                return callPreOrderPurchase('card', {nonce: nonce, vaultConsent: vaultConsent}, ddcSessionId);
            },
            onApm: function(apmData, paymentCode) {
                return callPreOrderPurchase('apm', {apm_data: apmData}, paymentCode);
            },
            onWalletToken: function(walletData, paymentCode) {
                return callPreOrderPurchase('wallet', {wallet_data: walletData}, paymentCode);
            },
            onSavedMethodSelected: function(token, ddcSessionId) {
                return callPreOrderPurchase('saved_method', {token: token}, ddcSessionId);
            },
            onActionComplete: function(result) {
                finalizeFromActionComplete(result.paymentCode);
            }
        };

        widget = therius.checkout(checkoutOptions);
        
        // PrestaShop 1.7+ loads the payment step dynamically via AJAX.
        // We must constantly check if our container is in the DOM and mount it if it is empty.
        setInterval(function() {
            var container = document.getElementById('therius-payment-element');
            if (container && container.children.length === 0 && container !== mountedContainer) {
                widget.mount(container);
                mountedContainer = container;
            }
        }, 500);
    }

    // Constructs the SDK's DeclineError when a recoveryAction is present
    // (activates CheckoutWidget's built-in smart recovery — retry/
    // switch_method/terminal), falling back to a plain Error otherwise.
    // Mirrors therius-plugin-shopware's therius-payment.plugin.js.
    function buildDeclineError(message, recoveryAction) {
        if (recoveryAction && window.TheriusSDK && window.TheriusSDK.DeclineError) {
            return new window.TheriusSDK.DeclineError(message, recoveryAction);
        }
        return new Error(message);
    }

    function callPreOrderPurchase(method, data, paymentCode) {
        return new Promise(function(resolve, reject) {
            if (!conditionsApproved()) {
                var msg = 'Please accept the terms and conditions before paying.';
                showError(msg);
                $('html, body').animate({scrollTop: $('#conditions-to-approve').offset().top - 100}, 500);
                reject(new Error(msg));
                return;
            }

            showStatus('Processing your payment…');

            var payload = Object.assign({}, data, {
                method: method,
                payment_code: paymentCode
            });

            $.ajax({
                url: window.theriusConfig.preOrderUrl,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                success: function(response) {
                    if (response.actionRequired) {
                        resolve(response.actionRequired);
                    } else if (response.success) {
                        showStatus('Payment confirmed — placing your order…');
                        theriusPaymentConfirmed = true;
                        resolve(true); // Must resolve non-undefined
                        $('#therius-method').val(method);
                        submitOrderForm(response.paymentCode || paymentCode);
                    } else {
                        showError(response.error || 'Payment failed');
                        reject(buildDeclineError(response.error || 'Payment failed', response.recoveryAction));
                    }
                },
                // purchase.php's decline branch returns HTTP 400, so a real
                // decline is routed here by jQuery, never through the
                // success callback's else-branch above.
                error: function(xhr) {
                    var msg = 'Payment failed';
                    var recoveryAction;
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        msg = xhr.responseJSON.error;
                        recoveryAction = xhr.responseJSON.recoveryAction;
                    }
                    showError(msg);
                    reject(buildDeclineError(msg, recoveryAction));
                }
            });
        });
    }

    function finalizeFromActionComplete(paymentCode) {
        showStatus('Verifying your payment…');
        $.ajax({
            url: window.theriusConfig.finalizeUrl,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({paymentCode: paymentCode}),
            success: function(response) {
                if (response.success) {
                    showStatus('Payment confirmed — placing your order…');
                    theriusPaymentConfirmed = true;
                    $('#therius-method').val('finalize');
                    submitOrderForm(paymentCode);
                } else {
                    showError('Payment failed during verification.');
                }
            },
            error: function(xhr) {
                showError('Payment verification failed.');
            }
        });
    }

});
