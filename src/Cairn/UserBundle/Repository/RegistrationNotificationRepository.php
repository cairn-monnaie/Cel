<?php

namespace Cairn\UserBundle\Repository;

use Cairn\UserBundle\Entity\WebPushSubscription;

use Doctrine\ORM\QueryBuilder;                                                 

/**
 * RegistrationNotificationPushRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class RegistrationNotificationRepository extends \Doctrine\ORM\EntityRepository
{
    public function findTargetsAround($posLat,$posLon)
    {
        $conn = $this->getEntityManager()->getConnection();                                          

        $sql = '
            SELECT *
             FROM web_push_subscription w
             INNER JOIN notification_data n ON w.notification_data_id = n.id
             INNER JOIN base_notification b ON (n.id = b.notification_data_id AND b.discr = "register" AND b.web_push_enabled = 1)
             INNER JOIN cairn_user u ON u.id = n.user_id
             INNER JOIN address a ON a.id = u.address_id
             WHERE st_distance_sphere(point(:lon, :lat),point(a.longitude, a.latitude))/1000 < b.radius  
               ';

        $stmt = $conn->prepare($sql);                         
        $stmt->execute(
            array(
                'lon' => $posLon,
                'lat'=>$posLat
            ));
        $webPushEndpoints =  $stmt->fetchAll(\PDO::FETCH_CLASS,WebPushSubscription::class);

        $sql = '
            SELECT device_tokens
             FROM notification_data n
             INNER JOIN base_notification b ON (n.id = b.notification_data_id AND b.discr = "register" AND b.app_push_enabled = 1)
             INNER JOIN cairn_user u ON u.id = n.user_id
             INNER JOIN address a ON a.id = u.address_id
             WHERE st_distance_sphere(point(:lon, :lat),point(a.longitude, a.latitude))/1000 < b.radius  
               ';

        $stmt = $conn->prepare($sql);                         
        $stmt->execute(
            array(
                'lon' => $posLon,
                'lat'=>$posLat
            ));
        $appPushEndpoints =  array_merge($stmt->fetchAll(\PDO::FETCH_COLUMN));

        return ['web_endpoints'=>$webPushEndpoints,'device_tokens'=>$appPushEndpoints];


    }
}
