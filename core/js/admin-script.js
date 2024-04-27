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

  var checkIndexingProgress = function () {
    $.ajax({
      url: aichwp_ajax.ajax_url,
      type: 'POST',
      data: {
        action: 'aichwp_get_indexing_progress'
      },
      success: function (response) {
        var progress = response.data || null

        console.log(progress)

        if (progress === null) {
          // aichwp_embeddings_progress is deleted, indicating indexing is completed
          $('#aichwp_indexing_status').html(
            "<span style='color: green;'>Indexing completed!</span>"
          )
          $('#aichwp_manual_indexing_button')
            .prop('disabled', false)
            .text('Re-Index Site Content')
          return
        }

        var completedCount = progress.processed
        var totalCount = progress.total
        var failedCount = progress.failed.length

        if (completedCount === 0 && totalCount === 0) {
          $('#aichwp_indexing_status').html(
            "<span style='color: red;'>Your site content has not been indexed!</span>"
          )
          $('#aichwp_manual_indexing_button')
            .prop('disabled', false)
            .text('Index Site Content')
        } else if (completedCount < totalCount) {
          $('#aichwp_indexing_status').text(
            completedCount +
              '/' +
              totalCount +
              ' documents indexed. ' +
              failedCount +
              ' failed.'
          )
          setTimeout(checkIndexingProgress, 2000)
        } else {
          if (failedCount > 0) {
            $('#aichwp_indexing_status').html(
              "<span style='color: green;'>Indexing completed with " +
                failedCount +
                ' failures.</span>'
            )
          } else {
            $('#aichwp_indexing_status').html(
              "<span style='color: green;'>All " +
                completedCount +
                ' documents indexed successfully.</span>'
            )
          }
          $('#aichwp_manual_indexing_button')
            .prop('disabled', false)
            .text('Re-Index Site Content')
        }
      },
      error: function () {
        $('#aichwp_indexing_status').html(
          'An error occurred while checking the indexing progress.'
        )
        $('#aichwp_manual_indexing_button').prop('disabled', false)
      }
    })
  }

  $('#aichwp_manual_indexing_button').on('click', function () {
    var button = $(this)
    button.prop('disabled', true)

    $.ajax({
      url: aichwp_ajax.ajax_url,
      type: 'POST',
      data: {
        action: 'aichwp_manual_indexing'
      },
      success: function (response) {
        checkIndexingProgress()
      },
      error: function () {
        $('#aichwp_indexing_status').text('An error occurred during indexing.')
        button.prop('disabled', false)
      }
    })
  })
})
