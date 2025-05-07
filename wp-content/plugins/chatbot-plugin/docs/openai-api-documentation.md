# OpenAI Chat API Documentation

This document provides a reference for integrating the OpenAI Chat API into the Chatbot Plugin for WordPress.

## Introduction

The OpenAI Chat API provides a way to interact with powerful language models like GPT-3.5 Turbo and GPT-4. This API allows our plugin to generate conversational text responses based on user inputs, creating a dynamic chatbot experience.

## Key Features

- Conversational AI capabilities using OpenAI's language models
- Context-aware responses that can remember conversation history
- Simple integration with JavaScript and Node.js (what we'll use for this WordPress plugin)
- Customizable response behavior through system instructions

## Basic Usage

### Installing the OpenAI SDK

To use the OpenAI API in a Node.js/JavaScript environment:

```bash
npm install openai
```

### API Key Requirements

You need an OpenAI API key which should be kept secure and not exposed in client-side code. In our WordPress plugin, we'll store this in the WordPress options table and use it server-side.

### Making a Simple Chat Request

Here's a basic example using JavaScript to call the Chat API:

```javascript
import OpenAI from 'openai';

// Initialize the OpenAI client
const openai = new OpenAI({
  apiKey: process.env['OPENAI_API_KEY'], // Fetch from secure storage
});

// Creating a chat completion
async function getChatResponse(userMessage) {
  try {
    const completion = await openai.chat.completions.create({
      model: 'gpt-3.5-turbo', // Use GPT-3.5 Turbo for a cost-effective solution
      messages: [
        { role: 'system', content: 'You are a helpful assistant.' },
        { role: 'user', content: userMessage }
      ],
    });
    
    return completion.choices[0].message.content;
  } catch (error) {
    console.error('Error calling OpenAI API:', error);
    return 'Sorry, I encountered an error processing your request.';
  }
}
```

## Conversation Management

### Message Formats

Each message in the conversation requires:

- **role**: Defines who is speaking ('system', 'user', or 'assistant')
- **content**: The actual message text

The API uses these roles:

- **system**: Sets behavior instructions for the assistant (not visible to users)
- **user**: Messages from the end user
- **assistant**: Responses from the AI assistant

### Maintaining Conversation History

For a coherent conversation, maintain the history of messages:

```javascript
// Initialize conversation with a system instruction
let conversationHistory = [
  { role: 'system', content: 'You are a helpful WordPress assistant who provides concise answers.' }
];

// Function to add a message and get a response
async function continueConversation(userMessage) {
  // Add user message to history
  conversationHistory.push({ role: 'user', content: userMessage });
  
  // Get response from OpenAI
  const completion = await openai.chat.completions.create({
    model: 'gpt-3.5-turbo',
    messages: conversationHistory,
  });
  
  // Get assistant's reply
  const assistantReply = completion.choices[0].message.content;
  
  // Add assistant response to history
  conversationHistory.push({ role: 'assistant', content: assistantReply });
  
  return assistantReply;
}
```

### Managing Context Length

The API has token limits for each model. When your conversation grows too long, you might need to:

1. Summarize previous context
2. Remove older messages
3. Only keep the most relevant parts of the conversation

## Streaming Responses

For a more dynamic experience, you can stream responses:

```javascript
async function streamChatResponse(userMessage) {
  // Add user message to conversation
  conversationHistory.push({ role: 'user', content: userMessage });
  
  const stream = await openai.chat.completions.create({
    model: 'gpt-3.5-turbo',
    messages: conversationHistory,
    stream: true,
  });
  
  let assistantResponse = '';
  
  // Process the stream
  for await (const chunk of stream) {
    const content = chunk.choices[0]?.delta?.content || '';
    assistantResponse += content;
    
    // Update UI with each chunk as it arrives
    updateChatUI(content);
  }
  
  // Add complete response to conversation history
  conversationHistory.push({ role: 'assistant', content: assistantResponse });
  
  return assistantResponse;
}
```

## WordPress Integration

For our WordPress plugin, we'll handle the API calls server-side via AJAX:

1. User sends a message through the frontend form
2. WordPress AJAX handler receives the request
3. Server-side PHP makes the API call using cURL or a library
4. Response is returned to the frontend
5. JavaScript updates the UI with the response

### PHP Implementation Example

```php
function chatbot_process_message() {
    // Check nonce for security
    check_ajax_referer('chatbot_nonce', 'security');
    
    // Get user message
    $user_message = sanitize_text_field($_POST['message']);
    
    // Get conversation history from session or database
    $conversation_history = get_chatbot_conversation_history();
    
    // Add user message to history
    $conversation_history[] = array(
        'role' => 'user',
        'content' => $user_message
    );
    
    // Get API key from WordPress options
    $api_key = get_option('chatbot_openai_api_key');
    
    // Prepare the request
    $request_body = array(
        'model' => 'gpt-3.5-turbo',
        'messages' => $conversation_history
    );
    
    // Call OpenAI API
    $response = wp_remote_post(
        'https://api.openai.com/v1/chat/completions',
        array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        )
    );
    
    if (is_wp_error($response)) {
        wp_send_json_error('Error calling OpenAI API');
        return;
    }
    
    $response_body = json_decode(wp_remote_retrieve_body($response), true);
    $assistant_reply = $response_body['choices'][0]['message']['content'];
    
    // Add assistant response to history
    $conversation_history[] = array(
        'role' => 'assistant',
        'content' => $assistant_reply
    );
    
    // Save updated conversation history
    save_chatbot_conversation_history($conversation_history);
    
    // Return response to frontend
    wp_send_json_success(array(
        'reply' => $assistant_reply
    ));
    
    wp_die();
}
add_action('wp_ajax_chatbot_process_message', 'chatbot_process_message');
add_action('wp_ajax_nopriv_chatbot_process_message', 'chatbot_process_message');
```

## Best Practices

1. **Security**:
   - Never expose API keys in frontend code
   - Validate and sanitize all user inputs
   - Implement rate limiting to prevent abuse

2. **Performance**:
   - Use streaming for a better user experience
   - Implement proper error handling
   - Consider caching common responses

3. **User Experience**:
   - Add "typing" indicators during API calls
   - Provide fallback responses for API failures
   - Consider adding a feedback mechanism

4. **Privacy**:
   - Be transparent about data usage
   - Consider data retention policies
   - Don't send sensitive user information to the API

## API Parameters

### Main Parameters

- **model**: The model to use (e.g., 'gpt-3.5-turbo', 'gpt-4')
- **messages**: Array of conversation messages
- **temperature**: Controls randomness (0-2, default 1)
- **max_tokens**: Maximum completion length
- **stream**: Boolean for response streaming
- **presence_penalty**: Penalizes repeated topics (-2.0 to 2.0)
- **frequency_penalty**: Penalizes repeated tokens (-2.0 to 2.0)

### Example with Parameters

```javascript
const completion = await openai.chat.completions.create({
  model: 'gpt-3.5-turbo',
  messages: conversationHistory,
  temperature: 0.7,
  max_tokens: 150,
  presence_penalty: 0.6,
  frequency_penalty: 0.6
});
```

## Handling Errors

Always implement proper error handling:

```javascript
try {
  const response = await openai.chat.completions.create({
    model: 'gpt-3.5-turbo',
    messages: conversationHistory
  });
  return response.choices[0].message.content;
} catch (error) {
  console.error('OpenAI API Error:', error);
  
  if (error.response) {
    console.error('Status:', error.response.status);
    console.error('Data:', error.response.data);
    
    // Handle specific error types
    if (error.response.status === 429) {
      return "I'm getting too many requests right now. Please try again later.";
    }
  }
  
  return "I'm sorry, I encountered an error. Please try again.";
}
```

## Resources

- [OpenAI API Documentation](https://platform.openai.com/docs/api-reference/chat)
- [OpenAI Node.js SDK](https://github.com/openai/openai-node)
- [OpenAI Chat Completions Guide](https://platform.openai.com/docs/guides/chat)
- [OpenAI Pricing](https://openai.com/pricing)
- [OpenAI Best Practices](https://platform.openai.com/docs/guides/production-best-practices)

## Conclusion

Integrating the OpenAI Chat API into our WordPress Chatbot plugin allows us to create a powerful, intelligent chatbot that can assist website visitors with a wide range of queries. Proper implementation requires attention to security, performance, and user experience considerations.