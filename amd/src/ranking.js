/**
 * Ranking block AMD module.
 *
 * Provides auto-refresh polling for ranking data and smooth animations.
 *
 * @module     block_ranking/ranking
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    var POLL_INTERVAL = 60000; // 60 seconds.
    var pollTimer = null;

    /**
     * Initialize the ranking module.
     *
     * @param {Number} courseid The current course ID.
     * @param {Number} pollinterval Optional poll interval in ms (default 60000).
     */
    function init(courseid, pollinterval) {
        if (pollinterval) {
            POLL_INTERVAL = pollinterval;
        }

        // Start polling for ranking updates.
        if (courseid && POLL_INTERVAL > 0) {
            startPolling(courseid);
        }

        // Add smooth transitions for ranking position changes.
        initAnimations();
    }

    /**
     * Start polling for ranking updates.
     *
     * @param {Number} courseid The course ID.
     */
    function startPolling(courseid) {
        // Only poll when the page is visible.
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stopPolling();
            } else {
                pollTimer = setInterval(function() {
                    fetchRanking(courseid);
                }, POLL_INTERVAL);
            }
        });

        pollTimer = setInterval(function() {
            fetchRanking(courseid);
        }, POLL_INTERVAL);
    }

    /**
     * Stop polling.
     */
    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    /**
     * Fetch updated ranking data via web service.
     *
     * @param {Number} courseid The course ID.
     */
    function fetchRanking(courseid) {
        var request = Ajax.call([{
            methodname: 'block_ranking_get_ranking',
            args: {courseids: [courseid]}
        }]);

        request[0].done(function(response) {
            if (response && response.leaderboard) {
                updateRankingDisplay(response.leaderboard);
            }
        }).fail(function(error) {
            // Silently fail on polling errors — don't disrupt the user.
            Notification.exception(error);
            stopPolling();
        });
    }

    /**
     * Update the ranking display with new data.
     *
     * @param {Array} leaderboard The updated leaderboard data.
     */
    function updateRankingDisplay(leaderboard) {
        var $generalList = $('[data-ranking-type="general"]');
        if (!$generalList.length || !leaderboard.length) {
            return;
        }

        // Compare current positions to detect changes.
        var $currentItems = $generalList.find('.ranking-item');
        var changed = false;

        $currentItems.each(function(index) {
            if (index < leaderboard.length) {
                var $item = $(this);
                var newPoints = leaderboard[index].points;
                var currentPoints = parseFloat($item.find('.ranking-points').text());

                if (currentPoints !== newPoints) {
                    changed = true;
                    // Animate the points change.
                    $item.find('.ranking-points').text(newPoints);
                    $item.addClass('ranking-updated');
                    setTimeout(function() {
                        $item.removeClass('ranking-updated');
                    }, 1500);
                }
            }
        });

        if (changed) {
            // Trigger a subtle pulse on the block to indicate an update.
            $generalList.closest('.block_ranking').addClass('ranking-refreshed');
            setTimeout(function() {
                $generalList.closest('.block_ranking').removeClass('ranking-refreshed');
            }, 1000);
        }
    }

    /**
     * Initialize entry animations for ranking items.
     */
    function initAnimations() {
        // Use IntersectionObserver for lazy animation when scrolled into view.
        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('ranking-visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, {threshold: 0.1});

            document.querySelectorAll('.ranking-item').forEach(function(item) {
                observer.observe(item);
            });
        }
    }

    return {
        init: init
    };
});
