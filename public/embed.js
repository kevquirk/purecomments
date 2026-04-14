(function () {
    const script = document.currentScript;
    if (!script) {
        return;
    }

    const scriptUrl = new URL(script.src, window.location.href);
    const scriptPath = scriptUrl.pathname.replace(/\/public\/embed\.js.*$/, '');
    const baseUrl = script.dataset.baseUrl
        ? script.dataset.baseUrl.replace(/\/$/, '')
        : (scriptUrl.origin + scriptPath);
    const container = document.getElementById('comments');
    if (!container) {
        return;
    }

    const fallbackStrings = {
        title:           'Comments',
        unavailable:     'Comments unavailable.',
        load_btn:        'Load comments',
        loading:         '💭 Loading comments…',
        load_error:      'Comments could not be loaded.',
        no_comments:     'No comments yet.',
        author_badge:    'Admin',
        reply_btn:       '↪ Reply',
        replying_to:     'Replying to comment #{id}',
        cancel_reply:    '❌ Cancel reply',
        form_heading:    'Leave a comment',
        privacy_link:    'Read the comment privacy notice',
        field_name:      'Name',
        field_email:     'Email (optional)',
        field_website:   'Website (optional)',
        field_comment:   'Comment (Markdown supported)',
        submitting:      'Sending…',
        submit_btn:      'Submit comment',
        submit_success:  'Thanks! Your comment is awaiting moderation.',
        submit_error:    'Oops! There was a problem submitting your comment.',
    };
    // s is rebuilt after the API responds (with server-translated strings as the base layer).
    // fallbackStrings is used only for the pre-load UI (load button, unavailable message).
    let s = Object.assign({}, fallbackStrings, (window.PureComments && window.PureComments.strings) || {});

    const header = document.createElement('div');
    header.className = 'comments-header';

    const title = document.createElement('h2');
    title.textContent = s.title;
    header.appendChild(title);

    const contentArea = document.createElement('div');
    contentArea.className = 'comments-content';

    container.appendChild(header);
    container.appendChild(contentArea);

    const configuredSlug = typeof container.dataset.postSlug === 'string'
        ? container.dataset.postSlug.trim()
        : '';
    const slug = normalizePostSlug(configuredSlug !== '' ? configuredSlug : derivePostSlugFromLocation(window.location.pathname));
    if (!slug) {
        contentArea.textContent = s.unavailable;
        return;
    }

    const loadButton = document.createElement('button');
    loadButton.type = 'button';
    loadButton.textContent = s.load_btn;
    loadButton.className = 'button load';
    loadButton.addEventListener('click', function () {
        contentArea.innerHTML = '';
        loadComments();
    });

    contentArea.appendChild(loadButton);

    function loadComments() {
        contentArea.innerHTML = '<p>' + s.loading + '</p>';
        apiFetch(
            baseUrl + '/api/comments/' + slug,
            baseUrl + '/api/index.php?endpoint=' + encodeURIComponent('comments/' + slug)
        )
            .then(handleResponse)
            .then(function (data) {
                renderCommentsSection(data || {});
            })
            .catch(function () {
                contentArea.innerHTML = '<p>' + s.load_error + '</p>';
            });
    }

    function renderCommentsSection(data) {
        if (data.strings && typeof data.strings === 'object') {
            s = Object.assign({}, fallbackStrings, data.strings, (window.PureComments && window.PureComments.strings) || {});
        }

        const comments = Array.isArray(data.comments) ? data.comments : [];
        const challengeQuestion = typeof data.challenge_question === 'string'
            ? data.challenge_question.trim()
            : '';
        const challengePlaceholder = typeof data.challenge_placeholder === 'string'
            ? data.challenge_placeholder.trim()
            : '';
        const privacyPolicyUrl = typeof data.privacy_policy_url === 'string'
            ? data.privacy_policy_url.trim()
            : '';

        contentArea.innerHTML = '';
        const listWrapper = document.createElement('div');
        listWrapper.className = 'comments-thread';
        if (comments.length === 0) {
            const empty = document.createElement('p');
            empty.textContent = s.no_comments;
            listWrapper.appendChild(empty);
        } else {
            comments.forEach(function (comment) {
                listWrapper.appendChild(renderCommentTree(comment));
            });
        }

        contentArea.appendChild(listWrapper);
        contentArea.appendChild(renderForm(challengeQuestion, challengePlaceholder, privacyPolicyUrl));
    }

    function renderCommentTree(comment) {
        const item = renderCommentItem(comment);
        if (Array.isArray(comment.children) && comment.children.length > 0) {
            const replies = document.createElement('div');
            replies.className = 'comment-children';
            appendReplies(comment.children, replies);
            item.appendChild(replies);
        }
        return item;
    }

    function appendReplies(children, container) {
        children.forEach(function (child) {
            const childItem = renderCommentItem(child);
            container.appendChild(childItem);
            if (Array.isArray(child.children) && child.children.length > 0) {
                appendReplies(child.children, container);
            }
        });
    }

    function renderCommentItem(comment) {
        const item = document.createElement('div');
        item.className = 'comment-item';

        const header = document.createElement('div');
        header.className = 'comment-header';

        const nameWrapper = document.createElement('div');
        nameWrapper.className = 'comment-meta';

        const nameElement = document.createElement('strong');
        if (comment.website) {
            const link = document.createElement('a');
            link.href = comment.website;
            link.textContent = comment.name;
            link.rel = 'noopener noreferrer nofollow ugc';
            link.target = '_blank';
            nameElement.appendChild(link);
        } else {
            nameElement.textContent = comment.name;
        }
        nameWrapper.appendChild(nameElement);

        if (comment.is_author) {
            const badge = document.createElement('span');
            badge.className = 'comment-badge';
            badge.textContent = s.author_badge;
            nameWrapper.appendChild(badge);
        }

        const time = document.createElement('time');
        const isoTimestamp = (comment.created_at || '').replace(' ', 'T') + 'Z';
        time.dateTime = isoTimestamp;
        time.textContent = formatRelativeTime(isoTimestamp);
        time.title = formatAbsoluteTime(isoTimestamp);

        header.appendChild(nameWrapper);
        header.appendChild(time);

        const body = document.createElement('div');
        body.className = 'comment-body';
        body.innerHTML = comment.content_html;

        const actions = document.createElement('div');
        actions.className = 'comment-actions';
        const reply = document.createElement('button');
        reply.type = 'button';
        reply.textContent = s.reply_btn;
        reply.addEventListener('click', function () {
            const form = container.querySelector('form.comments-form');
            if (!form) {
                return;
            }
            const parentInput = form.querySelector('input[name="parent_id"]');
            const replyBox = form.querySelector('.replying-to');
            const replyText = replyBox ? replyBox.querySelector('span') : null;
            if (!parentInput || !replyBox || !replyText) {
                return;
            }
            parentInput.value = comment.id;
            replyText.textContent = s.replying_to.replace('{id}', comment.id);
            replyBox.classList.remove('hidden');
            form.scrollIntoView({ behavior: 'smooth' });
        });
        actions.appendChild(reply);

        item.appendChild(header);
        item.appendChild(body);
        item.appendChild(actions);

        return item;
    }

    function renderForm(challengeQuestion, challengePlaceholder, privacyPolicyUrl) {
        const form = document.createElement('form');
        form.className = 'comments-form';
        form.noValidate = true;

        const heading = document.createElement('h3');
        heading.textContent = s.form_heading;
        form.appendChild(heading);

        if (privacyPolicyUrl !== '') {
            const privacyNote = document.createElement('p');
            privacyNote.className = 'comment-privacy-link';
            const privacyAnchor = document.createElement('a');
            privacyAnchor.href = privacyPolicyUrl;
            privacyAnchor.textContent = s.privacy_link;
            privacyNote.appendChild(privacyAnchor);
            form.appendChild(privacyNote);
        }

        const replying = document.createElement('div');
        replying.className = 'replying-to hidden';
        const replyText = document.createElement('span');
        replying.appendChild(replyText);
        const cancelReply = document.createElement('button');
        cancelReply.type = 'button';
        cancelReply.textContent = s.cancel_reply;
        cancelReply.addEventListener('click', function () {
            const parentInput = form.querySelector('input[name="parent_id"]');
            if (parentInput) {
                parentInput.value = '';
            }
            replyText.textContent = '';
            replying.classList.add('hidden');
        });
        replying.appendChild(cancelReply);
        form.appendChild(replying);

        form.appendChild(buildLabelInput(s.field_name, 'text', 'name', true));
        form.appendChild(buildLabelInput(s.field_email, 'email', 'email', false));
        form.appendChild(buildLabelInput(s.field_website, 'url', 'website', false));

        const commentLabel = document.createElement('label');
        commentLabel.textContent = s.field_comment;
        const textarea = document.createElement('textarea');
        textarea.name = 'content';
        textarea.required = true;
        textarea.style.overflowY = 'hidden';
        textarea.style.minHeight = '7rem';
        textarea.addEventListener('input', function () {
            autoGrow(textarea);
        });
        autoGrow(textarea);
        commentLabel.appendChild(textarea);
        form.appendChild(commentLabel);

        form.appendChild(buildLabelInput(challengeQuestion, 'text', 'surname', true, challengePlaceholder));

        const honeypot = document.createElement('input');
        honeypot.type = 'text';
        honeypot.name = 'trap_field';
        honeypot.autocomplete = 'off';
        honeypot.tabIndex = -1;
        honeypot.className = 'hp-field';
        form.appendChild(honeypot);

        const parentInput = document.createElement('input');
        parentInput.type = 'hidden';
        parentInput.name = 'parent_id';
        form.appendChild(parentInput);

        const slugInput = document.createElement('input');
        slugInput.type = 'hidden';
        slugInput.name = 'post_slug';
        slugInput.value = slug;
        form.appendChild(slugInput);

        const status = document.createElement('p');
        status.className = 'form-status';
        form.appendChild(status);

        const submit = document.createElement('button');
        submit.type = 'submit';
        submit.textContent = s.submit_btn;
        submit.className = 'button';
        form.appendChild(submit);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            status.textContent = s.submitting;
            status.classList.remove('success');
            status.classList.remove('error');

            const parentInput = form.querySelector('input[name="parent_id"]');
            const payload = {
                post_slug: slug,
                parent_id: parentInput ? parentInput.value : '',
                name: form.name.value.trim(),
                email: form.email.value.trim(),
                website: form.website.value.trim(),
                content: form.content.value.trim(),
                trap_field: form.trap_field.value.trim(),
                surname: form.surname.value.trim(),
            };

            apiFetch(
                baseUrl + '/api/submit-comment',
                baseUrl + '/api/index.php?endpoint=' + encodeURIComponent('submit-comment'),
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(payload),
                }
            )
                .then(handleResponse)
                .then(function (data) {
                    status.textContent = data.message || s.submit_success;
                    status.classList.add('success');
                    form.reset();
                    if (parentInput) {
                        parentInput.value = '';
                    }
                    const replyBox = form.querySelector('.replying-to');
                    if (replyBox) {
                        const span = replyBox.querySelector('span');
                        if (span) {
                            span.textContent = '';
                        }
                        replyBox.classList.add('hidden');
                    }
                })
                .catch(function () {
                    status.textContent = s.submit_error;
                    status.classList.add('error');
                });
        });

        return form;
    }

    function buildLabelInput(text, type, name, required, placeholder) {
        const label = document.createElement('label');
        label.textContent = text;
        const input = document.createElement('input');
        input.type = type;
        input.name = name;
        if (placeholder) {
            input.placeholder = placeholder;
        }
        if (required) {
            input.required = true;
        }
        label.appendChild(input);
        return label;
    }

    function handleResponse(response) {
        if (!response.ok) {
            throw new Error('Request failed');
        }
        return response.json();
    }

    function formatRelativeTime(isoString) {
        const date = new Date(isoString);
        if (Number.isNaN(date.getTime())) {
            return isoString;
        }
        const now = new Date();
        const diffMs = now.getTime() - date.getTime();
        const diffSeconds = Math.floor(diffMs / 1000);
        const diffMinutes = Math.floor(diffSeconds / 60);
        const diffHours = Math.floor(diffMinutes / 60);
        const diffDays = Math.floor(diffHours / 24);

        if (diffSeconds < 45) return 'Just now';
        if (diffMinutes < 60) return `${diffMinutes} minute${diffMinutes === 1 ? '' : 's'} ago`;
        if (diffHours < 24) return `${diffHours} hour${diffHours === 1 ? '' : 's'} ago`;
        if (diffDays < 30) return `${diffDays} day${diffDays === 1 ? '' : 's'} ago`;

        return formatAbsoluteTime(isoString);
    }

    function formatAbsoluteTime(isoString) {
        const date = new Date(isoString);
        if (Number.isNaN(date.getTime())) {
            return isoString;
        }
        const day = String(date.getUTCDate()).padStart(2, '0');
        const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const month = monthNames[date.getUTCMonth()];
        const year = date.getUTCFullYear();
        const hours = String(date.getUTCHours()).padStart(2, '0');
        const minutes = String(date.getUTCMinutes()).padStart(2, '0');
        return `${day} ${month} ${year} at ${hours}:${minutes}`;
    }

    function autoGrow(element) {
        element.style.height = 'auto';
        element.style.height = element.scrollHeight + 'px';
    }

    function apiFetch(primaryUrl, fallbackUrl, init) {
        return fetch(primaryUrl, init).then(function (response) {
            if (response.status === 404 && fallbackUrl) {
                return fetch(fallbackUrl, init);
            }
            return response;
        }).catch(function () {
            if (!fallbackUrl) {
                throw new Error('Request failed');
            }
            return fetch(fallbackUrl, init);
        });
    }

    function derivePostSlugFromLocation(pathname) {
        const trimmed = String(pathname || '').replace(/^\/+|\/+$/g, '');
        if (trimmed === '') {
            return 'home';
        }

        const parts = trimmed.split('/').filter(Boolean);
        if (parts.length === 0) {
            return 'home';
        }

        const last = parts[parts.length - 1];
        const withoutExtension = last.replace(/\.[a-z0-9]{1,10}$/i, '');
        if (withoutExtension.toLowerCase() === 'index') {
            return 'home';
        }
        try {
            return decodeURIComponent(withoutExtension);
        } catch (error) {
            return withoutExtension;
        }
    }

    function normalizePostSlug(value) {
        const normalized = String(value || '')
            .toLowerCase()
            .replace(/[^a-z0-9\-]+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-+|-+$/g, '');

        return normalized;
    }
})();
