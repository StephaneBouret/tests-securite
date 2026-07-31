<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260731125156 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_comment (id INT AUTO_INCREMENT NOT NULL, content VARCHAR(1000) NOT NULL, created_at DATETIME NOT NULL, author_id INT NOT NULL, INDEX IDX_CC794C66F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_comment ADD CONSTRAINT FK_CC794C66F675F31B FOREIGN KEY (author_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_comment DROP FOREIGN KEY FK_CC794C66F675F31B');
        $this->addSql('DROP TABLE user_comment');
    }
}
