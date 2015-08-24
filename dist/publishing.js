(function ($) {
  var formVals;
  var t = window.konziloPublish;
  var toggleQueue = function () {
    if ($('input[name=queue_items]').attr('checked')) {
      $('.queue-settings').slideDown();
      $('input[value=queue_last]').attr('checked', 'checked');
    }
    else {
      $('.queue-settings').slideUp();
    }
  };
  toggleQueue();

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
  });

  $('input[name=queue_items]').change(toggleQueue);
}(jQuery));
