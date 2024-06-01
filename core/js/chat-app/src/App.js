// App.js
import React, { useState, useEffect, useRef } from 'react'
import { sendMessage } from './chatApi'
import { AvatarImage, AvatarFallback, Avatar } from '@radix-ui/react-avatar'
import DOMPurify from 'dompurify'

import {
  UserIcon,
  MessageCircleIconLarge,
  CloseIcon,
  LoadingIcon
} from './icons'
import {
  aichwpBgColor,
  aichwpAIChatMessageBgColor,
  aichwpAIChatMessageTextColor,
  aichwpUserChatMessageBgColor,
  aichwpUserChatMessageTextColor,
  aichwpSendButtonColor,
  aichwpSendButtonTextColor,
  aichwpChatOpenButtonColor,
  aichwpChatClearChatTextColor
} from './styles'
import {
  chatWelcomeMessage,
  initialSuggestedQuestion1,
  initialSuggestedQuestion2,
  initialSuggestedQuestion3
} from './chatoptions'

export default function App() {
  const [chatHistory, setChatHistory] = useState([])
  const [chatInput, setChatInput] = useState('')
  const [chatMinimized, setChatMinimized] = useState(true)
  const [isLoading, setIsLoading] = useState(false)
  const [isAdminPage, setIsAdminPage] = useState(false)
  const [isSending, setIsSending] = useState(false)
  const [showSuggestedQuestions, setShowSuggestedQuestions] = useState(true)
  const [autoSendMessage, setAutoSendMessage] = useState(false)

  const chatWindowRef = useRef(null)

  useEffect(() => {
    const isAdmin = window.location.href.includes('/wp-admin/')
    setIsAdminPage(isAdmin)
    isAdmin && localStorage.setItem('aichwp_chat_minimized', 'false')
  }, [])

  useEffect(() => {
    const storedHistory = localStorage.getItem('aichwp_chat_history')
    if (storedHistory) {
      setChatHistory(JSON.parse(storedHistory))
      scrollToBottom(false)
      setShowSuggestedQuestions(false)
    } else {
      setChatHistory([{ role: 'AI', content: chatWelcomeMessage }])
    }
  }, [])

  useEffect(() => {
    const storedChatMinimized = localStorage.getItem('aichwp_chat_minimized')
    if (storedChatMinimized !== null) {
      setChatMinimized(storedChatMinimized === 'true')
    }
  }, [])

  const handleSuggestedQuestionClick = (question) => {
    setChatInput(question)
    setShowSuggestedQuestions(false)
    setAutoSendMessage(true)
  }

  useEffect(() => {
    if (chatInput.trim() !== '' && autoSendMessage) {
      handleSendMessage()
      setAutoSendMessage(false)
    }
  }, [autoSendMessage])

  const handleSendMessage = async () => {
    if (chatInput.trim() !== '' && !isSending) {
      const strippedInput = chatInput.replace(/<[^>]*>/g, '')
      const userMessage = { role: 'Human', content: strippedInput }
      const updatedHistory = [...chatHistory, userMessage]
      setChatHistory(updatedHistory)
      scrollToBottom(true)
      setChatInput('')
      setIsSending(true)
      setShowSuggestedQuestions(false)

      try {
        setIsLoading(true)
        const response = await sendMessage(chatInput, updatedHistory)
        const assistantMessage = { role: 'AI', content: response.response }
        const finalHistory = [...updatedHistory, assistantMessage]
        setChatHistory(finalHistory)
        scrollToBottom(true)

        localStorage.setItem(
          'aichwp_chat_history',
          JSON.stringify(
            finalHistory.map((message) => ({
              ...message,
              content: message.content.replace(/<br>/g, '\n')
            }))
          )
        )

        setIsLoading(false)
      } catch (error) {
        console.error('Error:', error)
        const errorMessage = { role: 'AI', content: error.message }
        const finalHistory = [...updatedHistory, errorMessage]
        setChatHistory(finalHistory)
        scrollToBottom(true)
        setIsLoading(false)
      }

      setIsSending(false)
    }
  }

  const handleInputChange = (event) => {
    setChatInput(event.target.value)
  }

  const handleToggleChat = () => {
    setChatMinimized(!chatMinimized)
    localStorage.setItem('aichwp_chat_minimized', String(!chatMinimized))
  }

  useEffect(() => {
    if (!chatMinimized) {
      scrollToBottom(false)
    }
  }, [chatMinimized])

  const formatMessageContent = (content, role) => {
    const sanitizedContent = DOMPurify.sanitize(content)
    const color =
      role === 'AI'
        ? aichwpAIChatMessageTextColor
        : aichwpUserChatMessageTextColor

    // Convert newlines to <br> tags and format links
    const formattedContent = sanitizedContent
      .replace(/\n/g, '<br>')
      .replace(
        /<a(.*?)>(.*?)<\/a>/g,
        `<a$1 class="font-bold hover:underline block mt-2" style="color: ${color}">$2</a>`
      )

    return formattedContent
  }

  const scrollToBottom = (isSmooth) => {
    chatWindowRef.current.style.scrollBehavior = isSmooth ? 'smooth' : 'auto'

    const doScroll = () => {
      chatWindowRef.current.scrollTop = chatWindowRef.current.scrollHeight
    }

    if (isSmooth) {
      setTimeout(doScroll, 20)
    } else {
      requestAnimationFrame(doScroll)
    }
  }

  const handleKeyPress = (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !isSending) {
      event.preventDefault()
      handleSendMessage()
    }
  }

  const handleClearChat = () => {
    setChatHistory([])
    localStorage.removeItem('aichwp_chat_history')
    setChatHistory([{ role: 'AI', content: chatWelcomeMessage }])
    setShowSuggestedQuestions(true)
  }

  return (
    <>
      <div
        className={`mainChatContainer ${
          isAdminPage ? 'relative' : 'fixed bottom-[75px] right-4'
        } z-[500] rounded-lg ${
          isAdminPage
            ? 'h-[500px] w-[90%] max-w-[450px]'
            : 'h-[50%] max-h-[500px] w-[90%] max-w-[450px]'
        } transform-gpu transition-transform duration-200 ease-in-out overflow-hidden origin-bottom-right shadow-2xl ${
          chatMinimized ? 'scale-0 duration-50' : 'scale-100'
        }`}
      >
        <div
          className='w-[100%] h-full flex flex-col'
          style={{ backgroundColor: aichwpBgColor }}
        >
          <div
            className='mainChatWindow flex-grow overflow-y-auto pb-2'
            ref={chatWindowRef}
          >
            <div className='relative min-h-full flex flex-col'>
              <div className='flex-grow overflow-y-auto space-y-4 px-2 py-6'>
                {chatHistory.map((message, index) => (
                  <div
                    key={index}
                    className={`flex items-end space-x-2 ${
                      message.role === 'AI' ? 'justify-end' : ''
                    }`}
                  >
                    {message.role === 'Human' && (
                      <Avatar className='h-8 w-8'>
                        <UserIcon className='w-full h-full' />
                      </Avatar>
                    )}
                    <div
                      className={`max-w-[70%] rounded-lg p-3 text-sm break-words`}
                      style={{
                        backgroundColor:
                          message.role === 'AI'
                            ? aichwpAIChatMessageBgColor
                            : aichwpUserChatMessageBgColor,
                        color:
                          message.role === 'AI'
                            ? aichwpAIChatMessageTextColor
                            : aichwpUserChatMessageTextColor
                      }}
                    >
                      <p
                        dangerouslySetInnerHTML={{
                          __html: formatMessageContent(
                            message.content,
                            message.role
                          )
                        }}
                      />
                    </div>
                  </div>
                ))}
                {isLoading && (
                  <div className='flex justify-center'>
                    <LoadingIcon />
                  </div>
                )}
              </div>
              <div className='flex-shrink-0 relative'>
                {showSuggestedQuestions && (
                  <div className='flex flex-col items-start space-y-2 mt-4 mb-3'>
                    {initialSuggestedQuestion1 && (
                      <button
                        className='rounded-lg py-2 px-4 text-sm ml-4 opacity-70 hover:opacity-100'
                        style={{
                          borderColor: aichwpUserChatMessageBgColor,
                          backgroundColor: aichwpUserChatMessageBgColor,
                          color: aichwpUserChatMessageTextColor
                        }}
                        onClick={() =>
                          handleSuggestedQuestionClick(
                            initialSuggestedQuestion1
                          )
                        }
                      >
                        {initialSuggestedQuestion1}
                      </button>
                    )}
                    {initialSuggestedQuestion2 && (
                      <button
                        className='rounded-lg py-2 px-4 text-sm ml-4 opacity-70 hover:opacity-100'
                        style={{
                          borderColor: aichwpUserChatMessageBgColor,
                          backgroundColor: aichwpUserChatMessageBgColor,
                          color: aichwpUserChatMessageTextColor
                        }}
                        onClick={() =>
                          handleSuggestedQuestionClick(
                            initialSuggestedQuestion2
                          )
                        }
                      >
                        {initialSuggestedQuestion2}
                      </button>
                    )}
                    {initialSuggestedQuestion3 && (
                      <button
                        className='rounded-lg py-2 px-4 text-sm ml-4 opacity-70 hover:opacity-100'
                        style={{
                          borderColor: aichwpUserChatMessageBgColor,
                          backgroundColor: aichwpUserChatMessageBgColor,
                          color: aichwpUserChatMessageTextColor
                        }}
                        onClick={() =>
                          handleSuggestedQuestionClick(
                            initialSuggestedQuestion3
                          )
                        }
                      >
                        {initialSuggestedQuestion3}
                      </button>
                    )}
                  </div>
                )}
                {chatHistory.some((message) => message.role === 'Human') && (
                  <a
                    className={`clearChatButton cursor-pointer text-sm font-medium opacity-80 hover:opacity-100 absolute bottom-[0px] left-[7px] text-left`}
                    onClick={handleClearChat}
                    style={{ color: aichwpChatClearChatTextColor }}
                  >
                    Clear Chat
                  </a>
                )}
              </div>
            </div>
          </div>
          <div
            className='mainChatInput border-t border-gray-200 px-4 py-3'
            style={{ backgroundColor: aichwpBgColor }}
          >
            <div className='flex items-center space-x-2'>
              <input
                className='chatInput flex-1 rounded-md border border-gray-300 bg-white py-2 px-3 text-sm focus:outline-none'
                placeholder='Type your message...'
                type='text'
                value={chatInput}
                onChange={handleInputChange}
                onKeyDown={handleKeyPress}
              />
              <button
                className='rounded-md px-4 py-2 text-sm font-medium'
                onClick={handleSendMessage}
                disabled={isSending}
                style={{
                  backgroundColor: aichwpSendButtonColor,
                  opacity: isSending ? '0.8' : '1',
                  color: aichwpSendButtonTextColor,
                  cursor: isSending ? 'not-allowed' : 'pointer'
                }}
              >
                Send
              </button>
            </div>
          </div>
        </div>
      </div>
      <button
        className={`${
          isAdminPage ? 'absolute -bottom-14 right-0' : 'fixed bottom-4 right-4'
        } p-2 rounded-full bg-blue-500 shadow-2xl z-[500] icon-button transition-transform duration-300 ease-in-out transform ${
          chatMinimized ? 'scale-100' : 'scale-85'
        } hover:scale-100`}
        style={{
          backgroundColor: aichwpChatOpenButtonColor
        }}
        onClick={handleToggleChat}
      >
        {chatMinimized ? (
          <MessageCircleIconLarge className='text-gray-500 w-8 h-8' />
        ) : (
          <CloseIcon className='text-gray-500 w-8 h-8' />
        )}
      </button>
    </>
  )
}
