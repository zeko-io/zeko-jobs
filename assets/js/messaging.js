/**
 * Zeko Messaging System JavaScript
 *
 * Handles all interactive functionality for the messaging system
 */

jQuery(document).ready(function($) {
    'use strict';


    // Cache DOM elements
    var $messaging = $('.zeko-messaging');
    var $conversationsList = $('.zeko-conversations-list');
    var $messageArea = $('.zeko-message-area');
    var $newMessageModal = $('.zeko-new-message-modal');
    var $newMessageBtn = $('.zeko-new-message-btn');
    var $closeNewMessage = $('.zeko-close-new-message');
    var $cancelNewMessage = $('.zeko-cancel-new-message');
    var $sendNewMessage = $('.zeko-send-new-message');
    var $recipientSearch = $('#zeko-recipient');
    var $userSearchResults = $('.zeko-user-search-results');
    var $messageInput = $('.zeko-message-input');
    var $sendMessageBtn = $('.zeko-send-message-btn');
    var $backToConversations = $('.zeko-back-to-conversations');
    var $loadMoreBtn = $('.zeko-load-more-btn');
    var $conversationSearch = $('.zeko-message-search');

    // Log element existence

    // Current state
    var currentUserId = $messaging.data('user-id');
    var currentConversationId = null;
    var currentRecipientId = null;
    var isLoading = false;
    var messageOffset = 0;
    var loadMoreTimeout = null;

    // Initialize messaging system
    function initMessaging() {
        if (!$messaging.length) {
            return;
        }


        // Set up event listeners
        setupEventListeners();

        // Load conversations
        loadConversations();

        // Set up real-time updates
        setupRealTimeUpdates();

    }

    // Set up event listeners
    function setupEventListeners() {
        // New message button
        $newMessageBtn.on('click', openNewMessageModal);

        // Close new message modal
        $closeNewMessage.on('click', closeNewMessageModal);
        $cancelNewMessage.on('click', closeNewMessageModal);

        // Send new message
        $sendNewMessage.on('click', sendNewMessage);

        // Recipient search
        $recipientSearch.on('input', debounce(searchUsers, 300));

        // User search result click
        $(document).on('click', '.zeko-user-result', selectRecipient);

        // Send message
        $sendMessageBtn.on('click', sendMessage);

        // Message input keypress
        $messageInput.on('keypress', function(e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Back to conversations
        $backToConversations.on('click', showConversationsList);

        // Load more messages
        $(document).on('click', '.zeko-load-more-btn', loadMoreMessages);

        // Conversation search
        $conversationSearch.on('input', debounce(searchAllMessages, 300));

        // Search result click → open that conversation
        $(document).on('click', '.zeko-search-result', openConversationFromSearch);

        // Conversation item click
        $(document).on('click', '.zeko-conversation-item', loadConversation);

        // Message templates
        $('.zeko-templates-btn').on('click', openTemplatesModal);
        $('.zeko-close-templates').on('click', closeTemplatesModal);
        $('.zeko-templates-modal').on('click', function(e) {
            if (e.target === this) {
                closeTemplatesModal();
            }
        });
        $('.zeko-save-template').on('click', saveMessageTemplate);
        $(document).on('click', '.zeko-template-delete', deleteMessageTemplate);
        $(document).on('click', '.zeko-template-use', useMessageTemplate);
        $('.zeko-template-picker').on('change', insertTemplateFromPicker);

        // Typing indicator
        $messageInput.on('input', function() {
            setTyping(true);
        });
        $messageInput.on('blur', function() {
            setTyping(false);
        });

        // Click outside modal to close
        $newMessageModal.on('click', function(e) {
            if (e.target === this) {
                closeNewMessageModal();
            }
        });
    }

    // Load conversations
    function loadConversations() {
        if (isLoading) return;

        isLoading = true;
        showLoading($conversationsList.find('.zeko-conversations'));


        $.ajax({
            url: zekoMessagingData.ajax_url,
            type: 'POST',
            data: {
                action: 'zeko_get_conversations',
                nonce: zekoMessagingData.nonce,
                user_id: currentUserId
            },
            success: function(response) {
                if (response.success) {
                    // Fix: Access conversations_html from response.data
                    var conversationsHtml = response.data.conversations_html;

                    $conversationsList.find('.zeko-conversations').html(conversationsHtml);
                 } else {
                    var errorMessage = response.data && response.data.message ? response.data.message : 'Unknown error';
                    showError(errorMessage);
                }
            },
            error: function(xhr, status, error) {
                showError('Error loading conversations: ' + error);
            },
            complete: function() {
                isLoading = false;
                hideLoading($conversationsList.find('.zeko-conversations'));
            }
        });
    }

    // Load conversation
    function loadConversation() {
        var $conversationItem = $(this);
        var conversationId = $conversationItem.data('conversation-id');

        if (isLoading || conversationId === currentConversationId) return;

        // Update active conversation
        $('.zeko-conversation-item').removeClass('active');
        $conversationItem.addClass('active');

        currentConversationId = conversationId;
        messageOffset = 0;

        // Show message area
        $messageArea.find('.zeko-no-conversation-selected').hide();
        $messageArea.find('.zeko-message-content').show();

        // Mobile: hide conversations list, show message area
        if (window.innerWidth <= 768) {
            $conversationsList.addClass('zeko-hidden-mobile');
            $messageArea.addClass('zeko-visible-mobile');
        }

        // Load conversation data
        loadConversationData(conversationId);

        // Load messages
        loadMessages(conversationId);
    }

    // Load conversation data
    function loadConversationData(conversationId) {

        $.ajax({
            url: zekoMessagingData.ajax_url,
            type: 'POST',
            data: {
                action: 'zeko_get_conversation_data',
                nonce: zekoMessagingData.nonce,
                conversation_id: conversationId
            },
            success: function(response) {
                if (response.success && response.data) {
                    var conversation = response.data.conversation;
                    var otherUser = response.data.other_user || { display_name: 'Loading...' };


                    // Fix: Use the other_user.ID directly since we have it
                    var otherUserId = otherUser.ID;

                    // Update conversation header
                    $('.zeko-conversation-title').text(otherUser.display_name);
                    currentRecipientId = otherUserId;

                } else {
                }
            },
            error: function(xhr, status, error) {
            }
        });
    }

    // Load messages
    function loadMessages(conversationId, append = false) {
        if (isLoading) return;

        isLoading = true;
        var $messagesContainer = $('.zeko-messages-list');
        var $loadMoreContainer = $('.zeko-load-more-messages');

        if (!append) {
            showLoading($messagesContainer);
            $loadMoreContainer.hide();
        } else {
            $loadMoreBtn.prop('disabled', true).text(zekoMessagingData.i18n.loading);
        }

        $.ajax({
            url: zekoMessagingData.ajax_url,
            type: 'POST',
            data: {
                action: 'zeko_get_messages',
                nonce: zekoMessagingData.nonce,
                conversation_id: conversationId,
                user_id: currentUserId,
                limit: 20,
                offset: messageOffset
            },
            success: function(response) {
                if (response.success) {
                    if (append) {
                        $messagesContainer.append(response.data.messages_html);
                    } else {
                        $messagesContainer.html(response.data.messages_html);
                    }

                    if (response.data.has_more) {
                        messageOffset = response.data.next_offset;
                        $loadMoreContainer.show();
                    } else {
                        $loadMoreContainer.hide();
                    }

                    // Scroll to bottom if not appending
                    if (!append) {
                        scrollToBottom();
                    }
                } else {
                    showError(response.data.message || 'Failed to load messages');
                }
            },
            error: function(xhr, status, error) {
                showError('Error loading messages: ' + error);
            },
            complete: function() {
                isLoading = false;
                if (!append) {
                    hideLoading($messagesContainer);
                } else {
                    $loadMoreBtn.prop('disabled', false).text(zekoMessagingData.i18n.load_more);
                }
            }
        });
    }

    // Load more messages
    function loadMoreMessages() {
        if (isLoading) return;
        loadMessages(currentConversationId, true);
    }

    // Send message
    function sendMessage() {

        var messageContent = $messageInput.val().trim();

        if (!messageContent || !currentConversationId) {
            return;
        }

        if (isLoading) {
            return;
        }

        isLoading = true;

        setTyping(false);

        var originalContent = $messageInput.val();
        $messageInput.val('').attr('disabled', true);
        $sendMessageBtn.prop('disabled', true).text(zekoMessagingData.i18n.sending);

        $.ajax({
            url: zekoMessagingData.ajax_url,
            type: 'POST',
            data: {
                action: 'zeko_send_message',
                nonce: zekoMessagingData.nonce,
                conversation_id: currentConversationId,
                recipient_id: currentRecipientId,
                message_content: messageContent
            },
            success: function(response) {
                if (response.success) {
                    // Add message to UI
                    $('.zeko-messages-list').append(response.data.message_html);

                    // Scroll to bottom
                    scrollToBottom();

                    // Update last message preview in conversation list
                    updateConversationPreview(currentConversationId, messageContent);

                    // Log activity (non-blocking)
                    setTimeout(function() {
                        zeko_log_user_activity(currentUserId, 'message_sent', {
                            recipient_id: currentRecipientId,
                            message_id: response.data.message_id
                        });
                    }, 100);
                } else {
                    showError(response.data.message || 'Failed to send message');
                    $messageInput.val(originalContent);
                }
            },
            error: function(xhr, status, error) {
                showError('Error sending message: ' + error);
                $messageInput.val(originalContent);
            },
            complete: function() {
                isLoading = false;
                $messageInput.attr('disabled', false).focus();
                $sendMessageBtn.prop('disabled', false).text(zekoMessagingData.i18n.send);
            }
        });
    }

    // Send new message (from modal)
    function sendNewMessage() {
        var recipientId = $recipientSearch.data('selected-user');
        var messageContent = $('.zeko-new-message-text').val().trim();

        if (!recipientId || !messageContent) {
            showError('Please select a recipient and enter a message');
            return;
        }

        if (isLoading) return;
        isLoading = true;

        $sendNewMessage.prop('disabled', true).text(zekoMessagingData.i18n.sending);

        $.ajax({
            url: zekoMessagingData.ajax_url,
            type: 'POST',
            data: {
                action: 'zeko_start_conversation',
                nonce: zekoMessagingData.nonce,
                recipient_id: recipientId,
                message_content: messageContent
            },
            success: function(response) {
                if (response.success) {
                    closeNewMessageModal();

                    // Load the new conversation
                    currentConversationId = response.data.conversation_id;
                    loadConversations();

                    // Show the new conversation
                    $('.zeko-conversation-item[data-conversation-id="' + currentConversationId + '"]').click();

                    // Show success message
                    showSuccess('Message sent successfully!');
                } else {
                    showError(response.data.message || 'Failed to send message');
                }
            },
            error: function(xhr, status, error) {
                showError('Error sending message: ' + error);
            },
            complete: function() {
                isLoading = false;
                $sendNewMessage.prop('disabled', false).text(zekoMessagingData.i18n.send_message);
            }
        });
    }

    // Search users
    function searchUsers() {
        var searchTerm = $recipientSearch.val().trim();
        if (searchTerm.length < 2) {
            $userSearchResults.html('');
            return;
        }

        $.ajax({
            url: zekoMessagingData.ajax_url,
            type: 'POST',
            data: {
                action: 'zeko_search_users',
                nonce: zekoMessagingData.nonce,
                search_term: searchTerm
            },
            success: function(response) {
                if (response.success) {
                    $userSearchResults.html(response.data.results_html);
                } else {
                    $userSearchResults.html('<div class="zeko-no-results">' + (response.data.message || 'No users found') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                $userSearchResults.html('<div class="zeko-no-results">Error searching users: ' + error + '</div>');
            }
        });
    }

    // Select recipient
    function selectRecipient() {
        var $userResult = $(this);
        var userId = $userResult.data('user-id');
        var userName = $userResult.find('.zeko-user-name').text();

        $recipientSearch.val(userName).data('selected-user', userId);
        $userSearchResults.html('');
    }

    // Search all messages (server-side, across every conversation)
    function searchAllMessages() {
        var searchTerm = $conversationSearch.val().trim();
        var $results = $('.zeko-message-search-results');

        if (searchTerm.length < 2) {
            $results.hide().html('');
            $('.zeko-conversations').show();
            return;
        }

        $.ajax({
            url: zekoMessagingData.ajax_url,
            type: 'POST',
            data: {
                action: 'zeko_search_messages',
                nonce: zekoMessagingData.nonce,
                search_term: searchTerm,
                limit: 10
            },
            success: function(response) {
                if (response.success) {
                    $results.html(response.data.results_html).show();
                    $('.zeko-conversations').hide();
                }
            },
            error: function() {
                $results.hide();
                $('.zeko-conversations').show();
            }
        });
    }

    // Open a conversation from a search result
    function openConversationFromSearch() {
        var conversationId = $(this).data('conversation-id');
        if (!conversationId) return;

        $('.zeko-message-search-results').hide();
        $('.zeko-conversations').show();
        $conversationSearch.val('');

        var $item = $('.zeko-conversation-item[data-conversation-id="' + conversationId + '"]');
        if ($item.length) {
            $item.trigger('click');
        } else {
            currentConversationId = conversationId;
            loadConversationData(conversationId);
            loadMessages(conversationId);
        }
    }

    /* =====================================================================
     * Message templates
     * =================================================================== */

    function openTemplatesModal() {
        $('.zeko-templates-modal').show();
        loadMessageTemplates();
    }

    function closeTemplatesModal() {
        $('.zeko-templates-modal').hide();
    }

    function loadMessageTemplates() {
        $.ajax({
            url: zekoMessagingData.ajax_url,
            type: 'POST',
            data: {
                action: 'zeko_get_message_templates',
                nonce: zekoMessagingData.nonce
            },
            success: function(response) {
                var $list = $('.zeko-template-list');
                if (response.success) {
                    $list.html(response.data.templates_html);
                } else {
                    $list.html('<div class="zeko-no-results">' + zekoMessagingData.i18n.error + '</div>');
                }
                populateTemplatePicker();
            }
        });
    }

    function populateTemplatePicker() {
        var $picker = $('.zeko-template-picker');
        var current = $picker.val();
        var options = '<option value="">' + $picker.find('option').first().text() + '</option>';
        $('.zeko-template-item').each(function() {
            var id = $(this).data('template-id');
            var title = $(this).find('.zeko-template-title').text();
            options += '<option value="' + id + '">' + $('<div>').text(title).html() + '</option>';
        });
        $picker.html(options);
        if (current) {
            $picker.val(current);
        }
    }

    function saveMessageTemplate() {
        var $title = $('.zeko-template-title-input');
        var $content = $('.zeko-template-content-input');
        $.ajax({
            url: zekoMessagingData.ajax_url,
            type: 'POST',
            data: {
                action: 'zeko_save_message_template',
                nonce: zekoMessagingData.nonce,
                template_title: $title.val(),
                template_content: $content.val()
            },
            success: function(response) {
                var $msg = $('.zeko-template-message');
                if (response.success) {
                    $msg.text(response.data.message).css('color', '#16a34a');
                    $title.val('');
                    $content.val('');
                    loadMessageTemplates();
                } else {
                    $msg.text(response.data.message || zekoMessagingData.i18n.error).css('color', '#dc2626');
                }
            }
        });
    }

    function deleteMessageTemplate() {
        var id = $(this).data('template-id');
        if (!confirm('Delete this template?')) return;
        $.ajax({
            url: zekoMessagingData.ajax_url,
            type: 'POST',
            data: {
                action: 'zeko_delete_message_template',
                nonce: zekoMessagingData.nonce,
                template_id: id
            },
            success: function(response) {
                if (response.success) {
                    loadMessageTemplates();
                }
            }
        });
    }

    function useMessageTemplate() {
        var id = $(this).data('template-id');
        var $item = $('.zeko-template-item[data-template-id="' + id + '"]');
        var content = $item.data('content') || '';
        closeTemplatesModal();
        if (content) {
            $messageInput.val(content);
            $messageInput.focus();
        }
    }

    function insertTemplateFromPicker() {
        var id = $(this).val();
        if (!id) return;
        var $item = $('.zeko-template-item[data-template-id="' + id + '"]');
        var content = $item.data('content') || '';
        if (content) {
            $messageInput.val(content);
        }
        $(this).val('');
    }

    /* =====================================================================
     * Typing indicators
     * =================================================================== */

    function setTyping(isTyping) {
        if (!currentConversationId) return;
        $.ajax({
            url: zekoMessagingData.ajax_url,
            type: 'POST',
            data: {
                action: 'zeko_set_typing',
                nonce: zekoMessagingData.nonce,
                conversation_id: currentConversationId,
                typing: isTyping ? '1' : '0'
            }
        });
    }

    function checkTyping() {
        if (!currentConversationId) return;
        $.ajax({
            url: zekoMessagingData.ajax_url,
            type: 'POST',
            data: {
                action: 'zeko_get_typing',
                nonce: zekoMessagingData.nonce,
                conversation_id: currentConversationId
            },
            success: function(response) {
                var $indicator = $('.zeko-typing-indicator');
                if (response.success && response.data.typing) {
                    $indicator.text(response.data.other_name + ' is typing...').show();
                } else {
                    $indicator.hide();
                }
            }
        });
    }

    // Show conversations list
    function showConversationsList() {
        $messageArea.find('.zeko-no-conversation-selected').show();
        $messageArea.find('.zeko-message-content').hide();
        currentConversationId = null;
        currentRecipientId = null;

        // Mobile: show conversations list, hide message area
        if (window.innerWidth <= 768) {
            $conversationsList.removeClass('zeko-hidden-mobile');
            $messageArea.removeClass('zeko-visible-mobile');
        }
    }

    // Open new message modal
    function openNewMessageModal() {
        $newMessageModal.show();
        $recipientSearch.val('').data('selected-user', '');
        $('.zeko-new-message-text').val('');
        $userSearchResults.html('');
        $recipientSearch.focus();
    }

    // Close new message modal
    function closeNewMessageModal() {
        $newMessageModal.hide();
    }

    // Update conversation preview
    function updateConversationPreview(conversationId, messageContent) {
        var $conversationItem = $('.zeko-conversation-item[data-conversation-id="' + conversationId + '"]');
        if ($conversationItem.length) {
            var words = messageContent.split(/\s+/);
            var previewText = words.slice(0, 10).join(' ') + (words.length > 10 ? '...' : '');
            $conversationItem.find('.zeko-conversation-preview').text('You: ' + previewText);
            $conversationItem.find('.zeko-conversation-time').text('just now');

            // Move to top of list
            $conversationItem.prependTo('.zeko-conversations');
        }
    }

    // Scroll to bottom of messages
    function scrollToBottom() {
        var $messagesContainer = $('.zeko-messages-container');
        $messagesContainer.scrollTop($messagesContainer[0].scrollHeight);
    }

    // Show loading state
    function showLoading($element) {
        $element.html('<div class="zeko-loading">' + zekoMessagingData.i18n.loading + '</div>');
    }

    // Hide loading state
    function hideLoading($element) {
        $element.find('.zeko-loading').remove();
    }

    // Show error message
    function showError(message) {
        var $error = $('<div class="zeko-error-message">' + message + '</div>');
        $('.zeko-messaging').prepend($error);
        setTimeout(function() {
            $error.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }

    // Show success message
    function showSuccess(message) {
        var $success = $('<div class="zeko-success-message">' + message + '</div>');
        $('.zeko-messaging').prepend($success);
        setTimeout(function() {
            $success.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }

    // Debounce function
    function debounce(func, wait) {
        return function() {
            var context = this, args = arguments;
            clearTimeout(loadMoreTimeout);
            loadMoreTimeout = setTimeout(function() {
                func.apply(context, args);
            }, wait);
        };
    }

    // Log user activity
    function zeko_log_user_activity(user_id, activity_type, activity_data) {
        $.ajax({
            url: zekoMessagingData.ajax_url,
            type: 'POST',
            data: {
                action: 'zeko_log_user_activity',
                nonce: zekoMessagingData.nonce,
                user_id: user_id,
                activity_type: activity_type,
                activity_data: activity_data
            }
        });
    }

    // Set up real-time updates
    function setupRealTimeUpdates() {
        // Check for new messages periodically
        setInterval(checkForNewMessages, 30000);

        // Check for unread count updates
        setInterval(updateUnreadCount, 60000);

        // Check whether the other participant is typing (polling)
        setInterval(checkTyping, 5000);
    }

    // Check for new messages
    function checkForNewMessages() {
        if (!currentConversationId) return;

        $.ajax({
            url: zekoMessagingData.ajax_url,
            type: 'POST',
            data: {
                action: 'zeko_check_new_messages',
                nonce: zekoMessagingData.nonce,
                conversation_id: currentConversationId,
                user_id: currentUserId,
                last_message_id: $('.zeko-message').last().data('message-id') || 0
            },
            success: function(response) {
                if (response.success && response.data.new_messages) {
                    // Append new messages
                    $('.zeko-messages-list').append(response.data.messages_html);

                    // Scroll to bottom if window is scrolled to bottom
                    var $container = $('.zeko-messages-container');
                    if ($container.scrollTop() + $container.innerHeight() >= $container[0].scrollHeight - 50) {
                        scrollToBottom();
                    } else {
                        // Show notification
                        showNewMessagesNotification(response.data.new_count);
                    }
                }
            }
        });
    }

    // Update unread count
    function updateUnreadCount() {
        $.ajax({
            url: zekoMessagingData.ajax_url,
            type: 'POST',
            data: {
                action: 'zeko_get_unread_count',
                nonce: zekoMessagingData.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateAdminBarUnreadCount(response.data.unread_count);
                }
            }
        });
    }

    // Update admin bar unread count
    function updateAdminBarUnreadCount(count) {
        var $adminBarItem = $('#wp-admin-bar-zeko-messaging');
        if ($adminBarItem.length) {
            var title = $adminBarItem.find('.ab-item').text().replace(/\s*\d+\s*$/, '');
            if (count > 0) {
                $adminBarItem.find('.ab-item').html(title + ' <span class="zeko-adminbar-unread">' + count + '</span>');
            } else {
                $adminBarItem.find('.ab-item').text(title);
            }
        }
    }

    // Show new messages notification
    function showNewMessagesNotification(count) {
        var $notification = $('<div class="zeko-new-messages-notification">' +
            count + ' new message' + (count > 1 ? 's' : '') + ' received. Click to view.' +
            '</div>');

        $('.zeko-messages-container').append($notification);

        $notification.on('click', function() {
            scrollToBottom();
            $(this).remove();
        });

        setTimeout(function() {
            $notification.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }

    // Initialize when DOM is ready
    initMessaging();

    // Expose some functions to global scope for debugging
    window.zekoMessaging = {
        loadConversations: loadConversations,
        loadConversation: loadConversation,
        sendMessage: sendMessage
    };
});
