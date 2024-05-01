// admin-script.js
jQuery(document).ready(function ($) {
  $('.aichwp-color-picker').wpColorPicker({
    change: function (event, ui) {
      var color = $(this)
        .attr('name')
        .replace('aichwp_settings[', '')
        .replace(']', '')
      var newColor = ui.color.toString()
      window.aichwp_color_vars[color] = newColor
      reloadChatApp()
    }
  })

  // Toggle post meta fields list
  $('.aichwp-toggle-meta-fields').on('click', function (e) {
    e.preventDefault()
    var postType = $(this).data('post-type')
    $(
      '.aichwp-post-meta-fields-list[data-post-type="' + postType + '"]'
    ).toggle()
  })

  // Reset color to default
  $('.aichwp-reset-color').on('click', function (e) {
    e.preventDefault()
    var color = $(this).data('color')
    var defaultColors = {
      aichwpBgColor: '#f3f4f6',
      aichwpAIChatMessageBgColor: '#3c82f6',
      aichwpAIChatMessageTextColor: '#ffffff',
      aichwpUserChatMessageBgColor: '#ffffff',
      aichwpUserChatMessageTextColor: '#001827',
      aichwpChatClearChatTextColor: '#4b5563',
      aichwpUserAvatarColor: '#001827',
      aichwpLoadingIconColor: '#3c82f6',
      aichwpSendButtonColor: '#3c82f6',
      aichwpSendButtonTextColor: '#ffffff',
      aichwpChatOpenButtonColor: '#3c82f6'
    }
    var defaultColor = defaultColors[color]
    var colorPicker = $('input[name="aichwp_settings[' + color + ']"]')
    colorPicker.wpColorPicker('color', defaultColor)
    window.aichwp_color_vars[color] = defaultColor
    reloadChatApp()
  })

  function reloadChatApp() {
    var chatAppContainer = $('#aichwp-chat-app')
    chatAppContainer.empty()
    $.getScript(
      aichwp_ajax.plugin_url + 'core/js/chat-app/build/static/js/aichwp.js'
    )
  }

  var indexingInProgress = false
  var checkIndexingProgress = function () {
    $.ajax({
      url: aichwp_ajax.ajax_url,
      type: 'POST',
      data: {
        action: 'aichwp_get_indexing_progress'
      },
      success: function (response) {
        var progress = response.data || null

        if (progress === null && indexingInProgress === false) {
          return
        }

        if (progress === null && indexingInProgress === true) {
          $('#aichwp_indexing_status').html(
            "<span style='color: #3c82f6;'>&nbsp;Indexing completed!</span>"
          )
          return
        }

        var completedCount = progress.processed
        var totalCount = progress.total

        $('#aichwp_indexing_status').html(
          "<span style='color: #3c82f6;'>&nbsp;" +
            "<img src='" +
            aichwp_ajax.plugin_url +
            "core/images/loading.gif' style='width: 20px; height: 20px;' />&nbsp;" +
            completedCount +
            '/' +
            totalCount +
            ' documents indexed'
        )
        indexingInProgress = true
        setTimeout(checkIndexingProgress, 2000)
      },
      error: function () {
        $('#aichwp_indexing_status').html(
          '&nbsp;An error occurred while checking the indexing progress.'
        )
      }
    })
  }

  // Check indexing progress initially
  checkIndexingProgress()
})
