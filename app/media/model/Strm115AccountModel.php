<?php

namespace app\media\model;

use think\Model;

class Strm115AccountModel extends Model
{
    protected $name = 'strm115_account';

    // thinkphp default timestamp fields are create_time/update_time; our schema uses createdAt/updatedAt
    protected $autoWriteTimestamp = false;
}
