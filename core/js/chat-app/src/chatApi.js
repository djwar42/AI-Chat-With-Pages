// chatAPI.js
export const sendMessage = async (query, history) => {
  try {
    // eslint-disable-next-line no-undef
    const response = await fetch(aichwp_ajax.ajax_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: new URLSearchParams({
        action: 'aichwp_chat',
        query: query,
        history: JSON.stringify(history),
        // eslint-disable-next-line no-undef
        aichwp_chat_nonce: aichwp_chat_nonce
      })
    })

    const data = await response.json()

    if (data.success) {
      return data.data
    } else {
      if (data.data === 'Invalid nonce, please refresh the page') {
        // Refresh the nonce and retry the request
        // eslint-disable-next-line no-undef
        const nonceResponse = await fetch(aichwp_ajax.ajax_url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: new URLSearchParams({
            action: 'aichwp_refresh_nonce'
          })
        })

        const nonceData = await nonceResponse.json()

        if (nonceData.success) {
          // Update the nonce variable with the new nonce
          // eslint-disable-next-line no-undef
          aichwp_chat_nonce = nonceData.data

          // Retry the chat request with the updated nonce
          return await sendMessage(query, history)
        } else {
          throw new Error('Failed to refresh nonce')
        }
      } else {
        throw new Error(data.data || 'Chat request failed')
      }
    }
  } catch (error) {
    console.error('Error:', error)
    return { response: `An error occurred: ${error.message}` }
  }
}
