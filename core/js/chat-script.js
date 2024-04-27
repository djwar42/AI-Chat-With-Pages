jQuery(document).ready(function ($) {
  var chatIcon = $('#aichwp-chat-icon')
  var chatContainer = $('#aichwp-chat-container')
  var chatOutput = $('#aichwp-chat-output')
  var chatHistory = localStorage.getItem('aichwp_chat_history')
  chatHistory = chatHistory ? JSON.parse(chatHistory) : []
  var chatInProgress =
    localStorage.getItem('aichwp_chat_in_progress') === 'true'
  var chatMinimized = localStorage.getItem('aichwp_chat_minimized') === 'true'

  // Display chat history on page load
  displayChatHistory(chatHistory)

  // Set chat box state based on stored value
  if (chatInProgress && !chatMinimized) {
    chatContainer.show()
  } else {
    chatContainer.hide()
  }

  chatIcon.click(function () {
    chatContainer.toggle()
    chatMinimized = !chatContainer.is(':visible')
    localStorage.setItem('aichwp_chat_minimized', chatMinimized)
  })

  $('#aichwp-chat-form').submit(function (e) {
    e.preventDefault()
    var query = $('#aichwp-chat-input').val()
    $.ajax({
      url: aichwp_ajax.ajax_url,
      type: 'POST',
      data: {
        action: 'aichwp_chat',
        query: query,
        history: JSON.stringify(chatHistory)
      },
      success: function (response) {
        if (response.success) {
          chatOutput.append('<p>Human: ' + response.data.query + '</p>')
          chatOutput.append('<p>AI: ' + response.data.response + '</p>')
          $('#aichwp-chat-input').val('')
          chatHistory = JSON.parse(response.data.history).history
          localStorage.setItem(
            'aichwp_chat_history',
            JSON.stringify(chatHistory)
          )
          localStorage.setItem('aichwp_chat_in_progress', 'true')
        }
      }
    })
  })

  function displayChatHistory(history) {
    chatOutput.empty()
    history.forEach(function (message) {
      chatOutput.append('<p>' + message.role + ': ' + message.content + '</p>')
    })
  }
})
