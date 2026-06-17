<?php

function tempCachePath(string $filename = '.sp_cache'): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.$filename;
}
