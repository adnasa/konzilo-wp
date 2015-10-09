(function ($, _) {
  'use strict';
  $(function () {
    var formVals;
    var t = window.konziloPublish;
    var queues = window.konziloQueues;

    var toggleQueue = function () {
      if ($('input[name=queue_items]').attr('checked')) {
        $('.queue-settings').slideDown();
        $('input[value=queue_last]').attr('checked', 'checked');
      }
      else {
        $('.queue-settings').slideUp();
      }
    };
    var toggleScheduled = function () {
      if ($('input[value=date]').attr('checked')) {
        $('#timestampdiv').slideDown();
      }
      else {
        $('#timestampdiv').slideUp();
      }
    };

    var QueueChanged = function () {
      var selected = parseInt($('select[name=konzilo_queue]').val());
      var queue = _.findWhere(queues, { id: selected});
      if (queue) {
        $('.socialform .channels input[type=checkbox]').each(function () {
          var profile = parseInt($(this).attr('data-profile'));
          if (!queue.accounts[profile] || !queue.accounts[profile].show) {
            $(this).removeAttr('checked').parent().hide();
          }
          else {
            $(this).parent().show();
          }
          if (queue.accounts[profile] && queue.accounts[profile].required) {
            $(this).attr('checked', 'checked').attr('disabled', 'disabled');
          }
          else {
            $(this).removeAttr('disabled');
          }
        });
        var textarea = $('.socialform textarea[name=konzilo_text]');
        if (queue.description && textarea && textarea.val().length === 0) {
          $('.socialform textarea[name=konzilo_text]').val(queue.description);
        }
      }
    }

    QueueChanged();
    toggleQueue();
    toggleScheduled();
    $('select[name=konzilo_queue]').change(QueueChanged);
    $('.edit-konzilo-status').click(function (e) {
      e.preventDefault();
      formVals = {
        'konzilo_type': $('input[name=konzilo_type]:checked').val(),
        'konzilo_queue': $('select[name=konzilo_queue]').val()
      };
      $('#konzilo-status-select').slideDown();
      $(this).hide();
    });

    $('.cancel-konzilo-status').click(function (e) {
      e.preventDefault();
      $('#konzilo-status-select').slideUp();
      $('input[value=' + formVals.konzilo_type + ']')
        .attr('checked', 'checked');
      $('select[name=konzilo_queue]').val(formVals.konzilo_queue);
      $('.edit-konzilo-status').show();
    });

    $('.save-konzilo-status').click(function(e) {
      e.preventDefault();
      var type = $('input[name=konzilo_type]:checked').val();
      var queue = $('select[name=konzilo_queue] option:selected')
          .html();
      var label = $('#konzilo-post-status');
      switch (type) {
      case 'stored':
        label.html(t.parked);
        break;
      case 'now':
        label.html(t.publishnow);
        break;
      case 'queue_last':
        label.html(t.lastin + ' ' + queue);
        break;
      case 'queue_first':
        label.html(t.firstin + ' ' + queue);
        break;
      }
      $('#konzilo-status-select').slideUp();
      $('.edit-konzilo-status').show();
    });

    $('input[name=konzilo_type]').change(function () {
      var type = $('input[name=konzilo_type]:checked').val();
      if (type !== 'queue_last' && type !== 'queue_first') {
        $('input[name=queue_items]').attr('checked', null);
        $('.queue-settings').slideUp();
      }
      if (type === 'date') {
        $('#timestampdiv').slideDown();
      }
      else {
        $('#timestampdiv').slideUp();
      }
    });

    $('input[name=queue_items]').change(toggleQueue);

    $('.socialform .toggle-content').click(function (e) {
      e.preventDefault();
      $('.socialform .toggle-schedule').removeClass('active');
      $('.socialform .toggle-content').addClass('active');
      $('.socialform .schedule').hide();
      $('.socialform .content-form').show();
    });

    $('.socialform .toggle-schedule').click(function (e) {
      e.preventDefault();
      $('.socialform .toggle-content').removeClass('active');
      $('.socialform .toggle-schedule').addClass('active');
      $('.socialform .schedule').show();
      $('.socialform .content-form').hide();
    });

    $('input[name=ready_for_publishing]').click(function() {
      $('select[name=post_status]').val('done');
    });
  });
}(jQuery, _));
