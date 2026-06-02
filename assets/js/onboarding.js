/**
 * Gift Reporter for MemberPress - Onboarding JavaScript
 *
 * @package MemberPressGiftReporter
 */

(function($) {
	'use strict';

	function dismissViaAjax(action, $container) {
		if (typeof mpgr_onboarding === 'undefined') {
			return;
		}

		$.post(mpgr_onboarding.ajax_url, {
			action: action,
			nonce: mpgr_onboarding.nonce
		}).done(function() {
			if ($container && $container.length) {
				$container.remove();
			}
		});
	}

	function postOnboarding(action, extra, $status) {
		if (typeof mpgr_onboarding === 'undefined') {
			return $.Deferred().reject().promise();
		}

		var data = $.extend({
			action: action,
			nonce: mpgr_onboarding.nonce
		}, extra || {});

		return $.post(mpgr_onboarding.ajax_url, data).done(function(response) {
			if ($status && $status.length && response && response.success && mpgr_onboarding.i18n) {
				if (action === 'mpgr_send_weekly_preview') {
					$status.text(mpgr_onboarding.i18n.preview_sent);
				}
			}
		}).fail(function() {
			if ($status && $status.length && mpgr_onboarding.i18n) {
				if (action === 'mpgr_send_weekly_preview') {
					$status.text(mpgr_onboarding.i18n.preview_failed);
				} else if (action === 'mpgr_enable_weekly_summary') {
					$status.text(mpgr_onboarding.i18n.enable_failed);
				}
			}
		});
	}

	function initWelcomeBanner() {
		$(document).on('click', '.mpgr-welcome-dismiss', function(e) {
			e.preventDefault();
			var $banner = $(this).closest('#mpgr-welcome-banner');
			dismissViaAjax('mpgr_dismiss_welcome', $banner);
		});

		$(document).on('click', '.mpgr-welcome-export', function(e) {
			e.preventDefault();
			var $status = $('#gift_status');
			if ($status.length) {
				$status.val('unclaimed');
			}
			if (typeof window.mpgrExportCSV === 'function') {
				window.mpgrExportCSV();
			}
		});
	}

	function initAdminBarPulse() {
		var $node = $('#wp-admin-bar-mpgr-gift-pulse');
		if (!$node.length || $node.find('.mpgr-dismiss-admin-bar').length) {
			return;
		}

		var $dismiss = $('<button type="button" class="mpgr-dismiss-admin-bar" aria-label="Dismiss">&times;</button>');
		$node.find('> .ab-item').first().append($dismiss);

		$dismiss.on('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			dismissViaAjax('mpgr_dismiss_admin_bar', $node);
		});
	}

	function initCliffhanger() {
		$(document).on('click', '.mpgr-cliffhanger-enable', function(e) {
			if (mpgr_onboarding && mpgr_onboarding.reminders_url) {
				window.location.href = mpgr_onboarding.reminders_url;
				e.preventDefault();
			}
		});

		$(document).on('click', '.mpgr-cliffhanger-snooze', function(e) {
			e.preventDefault();
			var $card = $(this).closest('.mpgr-cliffhanger');
			dismissViaAjax('mpgr_snooze_cliffhanger', $card);
		});
	}

	function initMondayPulse() {
		$(document).on('click', '.mpgr-monday-pulse-enable', function(e) {
			e.preventDefault();
			var $card = $(this).closest('#mpgr-monday-pulse');
			var $status = $card.find('.mpgr-monday-pulse__status');
			postOnboarding('mpgr_enable_weekly_summary', {}, $status).done(function(response) {
				if (response && response.success) {
					$card.remove();
				}
			});
		});

		$(document).on('click', '.mpgr-monday-pulse-preview', function(e) {
			e.preventDefault();
			var $card = $(this).closest('#mpgr-monday-pulse');
			var $status = $card.find('.mpgr-monday-pulse__status');
			$status.text('');
			postOnboarding('mpgr_send_weekly_preview', {}, $status);
		});

		$(document).on('click', '.mpgr-monday-pulse-dismiss', function(e) {
			e.preventDefault();
			var $card = $(this).closest('#mpgr-monday-pulse');
			dismissViaAjax('mpgr_dismiss_monday_pulse', $card);
		});
	}

	$(function() {
		initWelcomeBanner();
		initAdminBarPulse();
		initCliffhanger();
		initMondayPulse();
	});
})(jQuery);
