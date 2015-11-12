(function ($, _) {
  'use strict';
  $(function () {
    var formVals, savedTime, fakeTimeClick = false;
    var t = window.konziloPublish;
    var queues = window.konziloQueues;
    var sendMessage = function (msg) {
      var iframe = $('#konzilo-iframe');
      if (iframe.length === 0) {
        return;
      }
      iframe[0].contentWindow.postMessage(msg, '*');
    };

    var updatePublish = function () {
      fakeTimeClick = true;
      $('#timestampdiv .save-timestamp').trigger('click');
      fakeTimeClick = false;
    }

    var setTime = function (time) {
      $('#aa').val(time.getFullYear());
      var month = time.getMonth() + 1;
      if (month < 10) {
        month = '0' + month;
      }
      $('#mm').val(month);
      $('#jj').val(time.getDate());
      $('#hh').val(time.getHours());
      $('#mn').val(time.getMinutes());
      updatePublish();
    };

    var resetTime = function () {
      $('#aa').val($('#cur_aa').val());
      $('#mm').val($('#cur_mm').val());
      $('#jj').val($('#cur_jj').val());
      $('#hh').val($('#cur_hh').val());
      $('#mn').val($('#cur_mn').val());
      updatePublish();
    }

    var resetSavedTime = function () {
      $('#aa').val(savedTime.aa);
      $('#mm').val(savedTime.mm);
      $('#jj').val(savedTime.jj);
      $('#hh').val(savedTime.hh);
      $('#mn').val(savedTime.mn);

    }

    var saveTime = function () {
      savedTime = {
        'aa': $('#aa').val(),
        'mm': $('#mm').val(),
        'jj': $('#jj').val(),
        'hh': $('#hh').val(),
        'mn': $('#mn').val()
      };
    }

    var findQueue = function (queue) {
      return _.find(queues, function (q) { return q.id === parseInt(queue); });
    }

    var toggleQueue = function () {
      if ($('input[name=queue_items]').attr('checked')) {
        $('.queue-settings').slideDown();
        $('input[value=queue_last]').attr('checked', 'checked');

        var queue = $('select[name=konzilo_queue]').val();
        var queueObj = findQueue(queue);
        setTime(new Date(queueObj.last_time));
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
    toggleQueue();

    $('#timestampdiv .save-timestamp').click(function () {
      if (!fakeTimeClick) {
        sendMessage({
          type: 'typeChanged',
          msg: 'date'
        });
        $('input[value=date]').attr('checked', 'checked');
        $('.save-konzilo-status').trigger('click');
      }
    });

    $('.edit-konzilo-status').click(function (e) {
      e.preventDefault();
      formVals = {
        'konzilo_type': $('input[name=konzilo_type]:checked').val(),
        'konzilo_queue': $('select[name=konzilo_queue]').val(),
      };
      saveTime();
      $('#konzilo-status-select').slideDown();
      $(this).hide();
    });

    $('.cancel-konzilo-status').click(function (e) {
      e.preventDefault();
      $('#konzilo-status-select').slideUp();
      $('input[name=konzilo_type]').attr('checked', null);
      $('input[value=' + formVals.konzilo_type + ']')
        .attr('checked', 'checked');
      if (formVals['konzilo_type'] !== 'queue_last' && formVals['konzilo_type'] !== 'queue_first') {
        $('input[name=queue_items]').attr('checked', null);
        $('.queue-settings').slideUp();
      }
      else {
        $('input[name=queue_items]').attr('checked', 'checked');
        $('.queue-settings').show();
      }
      $('select[name=konzilo_queue]').val(formVals.konzilo_queue);
      $('.edit-konzilo-status').show();
      resetSavedTime();
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
      else {
        var queue = $('select[name=konzilo_queue]').val();
        var type = $('input[name=konzilo_type]:checked').val();
        var queueObj = findQueue(queue);
        setTime(new Date(type === 'queue_last' ?
                         queueObj.last_time : queueObj.first_time));
      }

      if (type === 'now') {
        resetTime();
      }
      if (type === 'parked') {
        resetSavedTime();
      }
      sendMessage({
        type: 'typeChanged',
        msg: type
      });
    });

    $('select[name=konzilo_queue]').change(function () {
      var queue = $(this).val();
      var type = $('input[name=konzilo_type]:checked').val();
      var queueObj = findQueue(queue);
      setTime(new Date(type === 'queue_last' ?
                       queueObj.last_time : queueObj.first_time));
      sendMessage({
        type: 'queueChanged',
        msg: queue,
        queueType: type
      });
    });
    $('input[name=queue_items]').change(toggleQueue);

    if ($('#konzilo-iframe').length === 0) {
      return;
    }
    var sent = false;
    var editLoaded = false;

    var listener = function (e) {
      if (e.data && e.data.type === 'editLoaded') {
        editLoaded = true;
      }
      if (e.data && e.data.type === 'editUnloaded') {
        editLoaded = false;
      }
      if (e.data.type === 'saved') {
        $('#konzilo-update').val(JSON.stringify(e.data.message));
      }
      if (e.data && e.data.type === 'height') {
        $('#konzilo-iframe').height(e.data.height + 40);
      }
    };
    window.addEventListener('message', listener);

    var truncateStr = function (str) {
      var trimmed = str.substr(0, 400);
      return trimmed.substr(0, Math.min(trimmed.length, trimmed.lastIndexOf(" ")))
    }

    var sendPostInfo = function () {
      // Find image
      var img = $('#postimagediv img').attr('src');
      var truncate = false;
      if (!img) {
        var image = $('#content').val().match(/<img.*?src="([^"]+)"/);
        if (image && image.length > 0) {
          img = image[1];
        }
      }
      var description = $('#excerpt').val();
      if (!description) {
        var desc = $('#content').val().match(/(.*)<!--more-->/);

        if (desc && desc.length > 0) {
          description = desc[1];
        }
        else {
          description = $('#content').val();
          truncate = true;
        }
      }

      // Strip all tags.
      var div = document.createElement("div");
      div.innerHTML = description;
      description = div.textContent || div.innerText || "";

      if (truncate) {
        description = truncateStr(description);
      }

      var info = {
        description: description,
        title: $('#title').val(),
        image: img,
      };
      sendMessage({
        type: 'postInfo',
        info: info
      });
    };

    var trackChanges = function () {
      sendPostInfo();
      setTimeout(trackChanges, 300);
    };
    trackChanges();
  });

}(jQuery, _));
