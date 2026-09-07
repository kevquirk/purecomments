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
        title: 'Comments',
        unavailable: 'Comments unavailable.',
        load_btn: 'Load comments',
        loading: 'Loading comments…',
        load_error: 'Comments could not be loaded.',
        no_comments: 'No comments yet.',
        author_badge: 'Admin',
        reply_btn: 'Reply',
        replying_to: 'Replying to comment #{id}',
        cancel_reply: 'Cancel reply',
        likes_count: '{count} Likes',
        likes_count_singular: '{count} Like',
        likes_count_plural: '{count} Likes',
        boosts_count: '{count} Boosts',
        boosts_count_singular: '{count} Boost',
        boosts_count_plural: '{count} Boosts',
        fediverse_badge: 'Fediverse',
        webmention_badge: 'Webmention',
        view_source: 'View original',
        form_heading: 'Leave a comment',
        privacy_link: 'Read the comment privacy notice',
        field_name: 'Name',
        field_email: 'Email (optional)',
        field_website: 'Website (optional)',
        field_comment: 'Comment (Markdown supported)',
        submitting: 'Sending…',
        submit_btn: 'Submit comment',
        submit_success: 'Thanks! Your comment is awaiting moderation.',
        submit_error: 'Oops! There was a problem submitting your comment.',
    };

    let lang = 'en';
    // s is rebuilt after the API responds (with server-translated strings as the base layer).
    // fallbackStrings is used only for the pre-load UI (load button, unavailable message).
    let s = Object.assign({}, fallbackStrings, (window.PureComments && window.PureComments.strings) || {});

    function createSvgIcon(type, className) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'pc-icon ' + (className || ''));
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('width', '16');
        svg.setAttribute('height', '16');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');

        if (type === 'heart') {
            svg.innerHTML = '<path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"/>';
        } else if (type === 'boost') {
            svg.innerHTML = '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>';
        } else if (type === 'reply-bubble') {
            svg.innerHTML = '<path d="M2.992 16.342a2 2 0 0 1 .094 1.167l-1.065 3.29a1 1 0 0 0 1.236 1.168l3.413-.998a2 2 0 0 1 1.099.092 10 10 0 1 0-4.777-4.719"/><path d="M8 12h.01"/><path d="M12 12h.01"/><path d="M16 12h.01"/>';
        } else if (type === 'reply') {
            svg.innerHTML = '<path d="M20 18v-2a4 4 0 0 0-4-4H4"/><path d="m9 17-5-5 5-5"/>';
        } else if (type === 'cancel') {
            svg.innerHTML = '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>';
        }
        return svg;
    }

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

    let apiData = null;
    let commentsLoaded = false;

    const fetchPromise = apiFetch(
        baseUrl + '/api/comments/' + slug,
        baseUrl + '/api/index.php?endpoint=' + encodeURIComponent('comments/' + slug)
    )
        .then(handleResponse)
        .then(function (data) {
            apiData = data;
            if (data.strings && typeof data.strings === 'object') {
                s = Object.assign({}, fallbackStrings, data.strings, (window.PureComments && window.PureComments.strings) || {});
            }
            if (data.language && typeof data.language === 'string') {
                lang = data.language;
            }
            title.textContent = s.title;
            if (loadButton && !commentsLoaded) {
                loadButton.textContent = s.load_btn;
            }
            return data;
        })
        .catch(function (err) {
            console.error('Failed to pre-fetch comments:', err);
        });

    loadButton.addEventListener('click', function () {
        commentsLoaded = true;
        contentArea.innerHTML = '';
        if (apiData) {
            renderCommentsSection(apiData);
        } else {
            contentArea.innerHTML = '<p>' + s.loading + '</p>';
            fetchPromise
                .then(function (data) {
                    if (data) {
                        renderCommentsSection(data);
                    } else {
                        contentArea.innerHTML = '<p>' + s.load_error + '</p>';
                    }
                })
                .catch(function () {
                    contentArea.innerHTML = '<p>' + s.load_error + '</p>';
                });
        }
    });

    contentArea.appendChild(loadButton);

    function renderReactionsSection(reactions) {
        if (!reactions || typeof reactions !== 'object') {
            return null;
        }
        const likes = Array.isArray(reactions.likes) ? reactions.likes : [];
        const reposts = Array.isArray(reactions.reposts) ? reactions.reposts : [];
        if (likes.length === 0 && reposts.length === 0) {
            return null;
        }

        const section = document.createElement('div');
        section.className = 'comments-reactions';

        if (likes.length > 0) {
            const label = (likes.length === 1 && s.likes_count_singular)
                ? s.likes_count_singular.replace('{count}', likes.length)
                : (s.likes_count_plural || s.likes_count || '{count} Likes').replace('{count}', likes.length);
            section.appendChild(renderReactionGroup('heart', label, likes));
        }
        if (reposts.length > 0) {
            const label = (reposts.length === 1 && s.boosts_count_singular)
                ? s.boosts_count_singular.replace('{count}', reposts.length)
                : (s.boosts_count_plural || s.boosts_count || '{count} Boosts').replace('{count}', reposts.length);
            section.appendChild(renderReactionGroup('boost', label, reposts));
        }

        return section;
    }

    function renderReactionGroup(iconType, labelText, items) {
        const group = document.createElement('div');
        group.className = 'reactions-group';

        const header = document.createElement('div');
        header.className = 'reactions-header';
        header.appendChild(createSvgIcon(iconType, 'reaction-icon'));
        const countSpan = document.createElement('span');
        countSpan.className = 'reactions-count';
        countSpan.textContent = labelText;
        header.appendChild(countSpan);
        group.appendChild(header);

        const facepile = document.createElement('div');
        facepile.className = 'facepile';

        items.forEach(function (item) {
            const a = document.createElement('a');
            a.className = 'facepile-item';
            a.href = item.website || item.source_url || '#';
            a.title = item.name || 'User';
            a.target = '_blank';
            a.rel = 'noopener noreferrer nofollow ugc';

            if (item.avatar_url) {
                const img = document.createElement('img');
                img.src = item.avatar_url;
                img.alt = item.name || 'User';
                img.className = 'facepile-avatar';
                img.loading = 'lazy';
                a.appendChild(img);
            } else {
                const placeholder = document.createElement('span');
                placeholder.className = 'facepile-avatar facepile-initials';
                placeholder.textContent = (item.name ? item.name.trim().charAt(0).toUpperCase() : '?');
                a.appendChild(placeholder);
            }
            facepile.appendChild(a);
        });

        group.appendChild(facepile);
        return group;
    }

    function renderCommentsSection(data) {
        if (data.strings && typeof data.strings === 'object') {
            s = Object.assign({}, fallbackStrings, data.strings, (window.PureComments && window.PureComments.strings) || {});
        }
        if (data.language && typeof data.language === 'string') {
            lang = data.language;
        }
        title.textContent = s.title;

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

        const reactionsNode = renderReactionsSection(data.reactions);
        if (reactionsNode) {
            contentArea.appendChild(reactionsNode);
        }

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

        if (comment.avatar_url) {
            const avatar = document.createElement('img');
            avatar.src = comment.avatar_url;
            avatar.alt = comment.name || 'Avatar';
            avatar.className = 'comment-avatar';
            avatar.loading = 'lazy';
            nameWrapper.appendChild(avatar);
        }

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

        if (comment.source_url) {
            const sourceLink = document.createElement('a');
            sourceLink.className = 'comment-source-link';
            sourceLink.href = comment.source_url;
            sourceLink.target = '_blank';
            sourceLink.rel = 'noopener noreferrer nofollow';
            sourceLink.title = s.view_source;
            sourceLink.appendChild(createSvgIcon('reply-bubble', 'source-icon'));
            const sourceLabel = document.createElement('span');
            sourceLabel.textContent = (comment.type === 'reply' || comment.type === 'mention') ? s.fediverse_badge : s.webmention_badge;
            sourceLink.appendChild(sourceLabel);
            nameWrapper.appendChild(sourceLink);
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
        reply.appendChild(createSvgIcon('reply', 'button-icon'));
        const replyTextSpan = document.createElement('span');
        replyTextSpan.textContent = ' ' + s.reply_btn;
        reply.appendChild(replyTextSpan);
        reply.addEventListener('click', function () {
            const form = container.querySelector('form.comments-form');
            if (!form) {
                return;
            }
            const parentInput = form.querySelector('input[name="parent_id"]');
            const replyBox = form.querySelector('.replying-to');
            const replyText = replyBox ? replyBox.querySelector('.replying-text') : null;
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
        replyText.className = 'replying-text';
        replying.appendChild(replyText);
        const cancelReply = document.createElement('button');
        cancelReply.type = 'button';
        cancelReply.className = 'button cancel-reply';
        cancelReply.appendChild(createSvgIcon('cancel', 'button-icon'));
        const cancelTextSpan = document.createElement('span');
        cancelTextSpan.textContent = ' ' + s.cancel_reply;
        cancelReply.appendChild(cancelTextSpan);
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

        if (typeof Intl !== 'undefined' && Intl.RelativeTimeFormat) {
            try {
                const rtf = new Intl.RelativeTimeFormat(lang, { numeric: 'auto' });
                if (diffSeconds < 45) {
                    return rtf.format(0, 'second');
                }
                if (diffMinutes < 60) {
                    return rtf.format(-diffMinutes, 'minute');
                }
                if (diffHours < 24) {
                    return rtf.format(-diffHours, 'hour');
                }
                if (diffDays < 30) {
                    return rtf.format(-diffDays, 'day');
                }
            } catch (e) {
                // fallback
            }
        }

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
        if (typeof Intl !== 'undefined' && Intl.DateTimeFormat) {
            try {
                const dtf = new Intl.DateTimeFormat(lang, {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false
                });
                return dtf.format(date);
            } catch (e) {
                // fallback
            }
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
