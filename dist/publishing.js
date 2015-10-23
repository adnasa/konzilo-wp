(function ($, _) {
  'use strict';
  $(function () {
    var formVals;
    var t = window.konziloPublish;
    var queues = window.konziloQueues;

    var sendMessage = function (msg) {
      var iframe = $('#konzilo-iframe');
      if (iframe.length === 0) {
        return;
      }
      iframe[0].contentWindow.postMessage(msg, '*');
    }

    var toggleQueue = function () {
      if ($('input[name=queue_items]').attr('checked')) {
        $('.queue-settings').slideDown();
        $('input[value=queue_last]').attr('checked', 'checked');
        var queue = $('select[name=konzilo_queue]').val();
        sendMessage({
          type: 'queueChanged',
          msg: queue,
          queueType: 'queue_last'
        });

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

    toggleQueue();
    toggleScheduled();

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
      case 'date':
        var date = $('#aa').val() + '-' + $('#mm').val() + '-' + $('#jj').val()
          + ' ' + $('#hh').val() + ':' + $('#mn').val();
        label.html(t.scheduled + ' ' + date);
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
      sendMessage({
        type: 'typeChanged',
        msg: type
      });
    });

    $('select[name=konzilo_queue]').change(function () {
      var queue = $(this).val();
      var type = $('input[name=konzilo_type]:checked').val();
      sendMessage({
        type: 'queueChanged',
        msg: queue,
        queueType: type
      });
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
    if ($('#konzilo-iframe').length === 0) {
      return;
    }
    $('input[name=ready_for_publishing]').click(function() {
      $('select[name=post_status]').val('done');
    });
    var sent = false;
    var listener = function (e) {
      if (e.data === 'saved') {
        sent = true;
        $('form#post').submit();
      }
      if (e.data && e.data.type === 'height') {
        $('#konzilo-iframe').height(e.data.height + 40);
      }
    }
    window.addEventListener('message', listener);

    $('form#post').submit(function(e) {
      if (!sent) {
        e.preventDefault();
        sendMessage({
          type: 'saveUpdate'
        });
      }
      else {
        sent = false;
      }
    });
  });
}(jQuery, _));
