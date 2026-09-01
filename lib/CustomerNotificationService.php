<?php
declare(strict_types=1);

final class CustomerNotificationService
{
    public function __construct(private readonly PDO $pdo) {}
    public function ready(): bool{try{return (bool)$this->pdo->query("SHOW TABLES LIKE 'gp_customer_notifications'")->fetchColumn();}catch(Throwable){return false;}}
    public function create(int $customerId,string $type,string $title,string $message,?string $entityType=null,?int $entityId=null): void
    {if(!$this->ready()||$customerId<1)return;$this->pdo->prepare('INSERT INTO gp_customer_notifications (customer_id,type,title,message,entity_type,entity_id) VALUES (?,?,?,?,?,?)')->execute([$customerId,mb_substr($type,0,40),mb_substr($title,0,160),mb_substr($message,0,500),$entityType,$entityId]);}
    public function list(int $customerId,int $limit=50): array
    {if(!$this->ready())return[];$limit=max(1,min(100,$limit));$q=$this->pdo->prepare('SELECT id,type,title,message,entity_type,entity_id,read_at,created_at FROM gp_customer_notifications WHERE customer_id=? ORDER BY id DESC LIMIT '.$limit);$q->execute([$customerId]);return array_map(static fn($r)=>['id'=>(int)$r['id'],'type'=>$r['type'],'title'=>$r['title'],'message'=>$r['message'],'entityType'=>$r['entity_type'],'entityId'=>$r['entity_id']===null?null:(int)$r['entity_id'],'readAt'=>$r['read_at'],'createdAt'=>$r['created_at']],$q->fetchAll());}
    public function unreadCount(int $customerId): int{if(!$this->ready())return 0;$q=$this->pdo->prepare('SELECT COUNT(*) FROM gp_customer_notifications WHERE customer_id=? AND read_at IS NULL');$q->execute([$customerId]);return(int)$q->fetchColumn();}
    public function markAllRead(int $customerId): void{if($this->ready())$this->pdo->prepare('UPDATE gp_customer_notifications SET read_at=COALESCE(read_at,NOW()) WHERE customer_id=?')->execute([$customerId]);}
    public function customerIdForContract(string $contractNumber): int{$q=$this->pdo->prepare('SELECT customer_id FROM gp_contracts WHERE contract_number=? ORDER BY id DESC LIMIT 1');$q->execute([$contractNumber]);return(int)($q->fetchColumn()?:0);}
}
