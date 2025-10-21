<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MutasiRelationManager extends RelationManager
{
    protected static string $relationship = 'mutasi';
    protected static ?string $title = 'History Mutasi';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('tanggal')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', direction: 'desc')
            ->paginationPageOptions([5, 25, 50, 100, 250])
            ->defaultPaginationPageOption(5)
            ->recordTitleAttribute('tanggal')
            ->columns([
                Tables\Columns\TextColumn::make('tanggal')
                    ->searchable()
                    ->dateTime('l, d F Y'),
                Tables\Columns\TextColumn::make('jenis_mutasi')
                    ->searchable(),
                Tables\Columns\TextColumn::make('jumlah')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('produk.nama_produk')
                    ->searchable(),
                Tables\Columns\TextColumn::make('lokasi.nama_lokasi')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->searchable()
                    ->label('Dibuat oleh'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->actions([
                //
            ])
            ->bulkActions([
                //
            ]);
    }
}
