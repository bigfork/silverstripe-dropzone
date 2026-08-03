<% if $Items %>
    <ul class="dropzone-readonly-files">
        <% loop $Items %>
            <li class="dropzone-readonly-file">
                <% if $canView %>
                    <a href="{$Link.ATT}" target="_blank" rel="noopener noreferrer">{$Title}</a>
                <% else %>
                    {$Title}
                <% end_if %>
                <span class="dropzone-readonly-size">{$Size}</span>
            </li>
        <% end_loop %>
    </ul>
<% else %>
    <p class="dropzone-readonly-empty"><%t Bigfork\SilverStripeDropzone\DropzoneField.NoFilesAttached 'No files attached' %></p>
<% end_if %>
