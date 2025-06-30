<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

# Sistema de Permissões - LaravelWithMongodbBoilerplate

## Visão Geral

O sistema de permissões foi projetado para suportar um ambiente de multitenancy com MongoDB, permitindo controle granular sobre diferentes níveis de acesso: Tenant, Account e User.

## Estrutura Principal

### Usuários
- **LandlordUser**: Usuários no database do landlord, com acesso a múltiplos tenants
- **TenantUser**: Usuários nos databases dos tenants, com papéis específicos por conta

### Papéis e Permissões
- **Role**: Define conjuntos de permissões
- **RoleTemplate**: Templates predefinidos para facilitar criação de papéis
- **AccountRole**: Associa papéis a usuários em contas específicas
- **LandlordTenantRole**: Associa papéis a landlord users em tenants específicos

### Módulos
- **Module**: Representa um tipo de conteúdo dinâmico (como "Posts", "Cursos", etc.)
- **ModuleItem**: Itens específicos dentro de um módulo

## Hierarquia de Permissões

O sistema suporta diferentes escopos de permissões:
- **all**: Acesso a todos os itens
- **account**: Acesso apenas aos itens da conta atual
- **owned**: Acesso apenas aos próprios itens

## Uso Básico

### Verificar Permissões no Controller
