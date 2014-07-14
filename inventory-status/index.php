<?php

/* toggle too slow response (just add // in front of this line)
sleep(1);
// */
usleep(250000);
echo json_encode(['42' => 5, '1337' => 1]);
