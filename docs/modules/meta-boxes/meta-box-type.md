<!--
    Generated from src/Modules/MetaBoxes/MetaBoxType.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# MetaBoxType

What kind of screen a box appears on.

Only two screens in WordPress render meta boxes for an editable object: the post edit screen and the comment edit screen. Terms and users have no meta-box concept at all — they take custom fields through action hooks that emit table rows.

The two differ in what saving them means, which is why this is a choice a box makes rather than something inferred: a post save arrives on `save_post` and may be an autosave or a revision, and a comment save arrives on `edit_comment` and can be neither.
