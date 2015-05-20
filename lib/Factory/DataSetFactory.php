<?php
/*
 * Spring Signage Ltd - http://www.springsignage.com
 * Copyright (C) 2015 Spring Signage Ltd
 * (DataSetFactory.php)
 */


namespace Xibo\Factory;


use Xibo\Entity\DataSet;
use Xibo\Exception\NotFoundException;
use Xibo\Helper\Log;
use Xibo\Storage\PDOConnect;

class DataSetFactory
{
    /**
     * @param array $sortOrder
     * @param array $filterBy
     * @return array[DisplayProfile]
     * @throws NotFoundException
     */
    public static function query($sortOrder = null, $filterBy = null)
    {
        $entries = array();
        $params = array();

        try {

            $sql  = "SELECT dataset.dataSetId, ";
            $sql .= "       dataset.dataSet, ";
            $sql .= "       dataset.description, ";
            $sql .= "       dataset.userId, ";
            $sql .= "       user.userName AS owner ";
            $sql .= "  FROM dataset ";
            $sql .= "   INNER JOIN `user` ON user.userId = dataset.userId ";

            // Sorting?
            if (is_array($sortOrder))
                $sql .= 'ORDER BY ' . implode(',', $sortOrder);

            Log::sql($sql, $params);

            foreach (PDOConnect::select($sql, $params) as $row) {
                $entries[] = (new DataSet())->hydrate($row);
            }

            return $entries;

        } catch (\Exception $e) {

            Log::error($e);

            throw new NotFoundException();
        }
    }
}