<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260731125335 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE purchase_order (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(30) NOT NULL, description VARCHAR(255) NOT NULL, total_cents INT NOT NULL, owner_id INT NOT NULL, UNIQUE INDEX UNIQ_21E210B2AEA34913 (reference), INDEX IDX_21E210B27E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE purchase_order ADD CONSTRAINT FK_21E210B27E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE purchase_order DROP FOREIGN KEY FK_21E210B27E3C61F9');
        $this->addSql('DROP TABLE purchase_order');
    }
}
