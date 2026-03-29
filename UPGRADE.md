## Upgrade notes

### 0.3.0

Story titles now come from the WordPress post title instead of being AI-generated. The old `title_prompt` setting and `generated_title` metadata are no longer used.

To clean up leftover data, run:

```bash
# Remove title_prompt from settings
wp eval "
\$o = get_option('knabbel_settings');
if (isset(\$o['title_prompt'])) {
    unset(\$o['title_prompt']);
    update_option('knabbel_settings', \$o);
    echo 'Removed title_prompt from settings.';
} else {
    echo 'No title_prompt found.';
}
"

# Remove generated_title from story state
wp post list --post_type=post --meta_key=_zw_knabbel_story_state --format=ids | xargs -n1 -I{} wp eval "
\$s = get_post_meta({}, '_zw_knabbel_story_state', true);
if (is_array(\$s) && isset(\$s['generated_title'])) {
    unset(\$s['generated_title']);
    update_post_meta({}, '_zw_knabbel_story_state', \$s);
    echo 'Cleaned post {}.';
}
"
```

This cleanup is optional — leftover data does not affect functionality.
