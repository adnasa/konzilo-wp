(function ($) {
  var iFrame = $('iframe.curate');
  var setSize = function () {
    var height = $(window).height();
    iFrame.height(height - 100);
  };
  setSize();
  $(window).resize(setSize);

}(jQuery));
