<?php
// POST /projects/rename — kept as an alias of /projects/update so any client
// still calling the old route keeps working. Body: { id, project_name }.
//
// New code should call /projects/update, which can change branding and the
// reply-from address in the same request.

require __DIR__ . '/update.php';
