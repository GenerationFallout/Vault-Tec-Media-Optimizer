-- Module:Infobox/Extension
-- À placer sur le wiki à l'adresse : Module:Infobox/Extension
--
-- Définit la structure de l'infobox {{Extension}} pour documenter
-- les extensions MediaWiki hébergées sur ce wiki.
--
-- Paramètres du modèle pris en charge :
--   nom              — nom de l'extension
--   état             — stable | bêta | expérimental | obsolète
--   image            — fichier image/logo (optionnel)
--   type1…typeN      — type(s) d'extension (ex. « média », « page spéciale »)
--   hook1…hookN      — hooks MediaWiki utilisés
--   description      — description courte
--   auteur           — auteur(s)
--   dernière version — numéro de version actuelle
--   licence          — licence (ex. GPL-2.0-or-later)
--   download         — lien de téléchargement (wikitexte)
--   mediawiki        — version MediaWiki minimale
--   php              — version PHP minimale
--   exemple          — URL d'un wiki utilisant l'extension

local p = {}

p.name  = 'Extension'
p.class = 'avt-infobox-extension'
p.style = { ['width'] = '22em' }

-- Couleur de fond de l'en-tête selon l'état déclaré
local stateStyle = {
	['stable']       = { ['background-color'] = '#2d6a2d', ['color'] = '#ffffff' },
	['bêta']         = { ['background-color'] = '#7a6000', ['color'] = '#ffffff' },
	['beta']         = { ['background-color'] = '#7a6000', ['color'] = '#ffffff' },
	['expérimental'] = { ['background-color'] = '#7a3500', ['color'] = '#ffffff' },
	['obsolète']     = { ['background-color'] = '#555555', ['color'] = '#cccccc' },
}

p.parts = {

	-- ── En-tête ──────────────────────────────────────────────────────────────
	{
		type  = 'title',
		value = 'nom',
		-- Couleur dynamique selon |état=
		style = function(localdata)
			local etat = localdata['état'] or localdata['etat'] or ''
			return stateStyle[etat:lower()] or { ['background-color'] = '#3b1e08', ['color'] = '#ffd700' }
		end,
	},

	-- ── Image / logo ─────────────────────────────────────────────────────────
	{
		type             = 'images',
		imageparameters  = { 'image' },
		defaultupright   = '1',
	},

	-- ── Tableau des métadonnées ───────────────────────────────────────────────
	{
		type = 'table',
		rows = {

			-- État
			{
				type  = 'row',
				label = 'État',
				value = function(localdata)
					local etat = localdata['état'] or localdata['etat']
					if not etat then return nil end
					-- Met en forme l'état avec une petite pastille colorée via une classe CSS
					return etat
				end,
			},

			-- Type(s) : type1, type2, … jusqu'à absence
			{
				type  = 'row',
				label = 'Type',
				value = function(localdata)
					local types = {}
					for i = 1, 15 do
						local t = localdata['type' .. i]
						if t then
							table.insert(types, t)
						else
							break
						end
					end
					if #types == 0 then return nil end
					return table.concat(types, ', ')
				end,
			},

			-- Hooks : hook1, hook2, … jusqu'à absence
			{
				type  = 'row',
				label = 'Hooks utilisés',
				value = function(localdata)
					local hooks = {}
					for i = 1, 30 do
						local h = localdata['hook' .. i]
						if h then
							-- Lien vers la doc MediaWiki du hook
							table.insert(hooks, h)
						else
							break
						end
					end
					if #hooks == 0 then return nil end
					-- Liste à puces (le '\n*' initial est géré par buildrow)
					return table.concat(hooks, '\n* ', '* ')
				end,
			},

			-- Description
			{
				type  = 'row',
				label = 'Description',
				value = 'description',
			},

			-- Auteur(s)
			{
				type  = 'row',
				label = 'Auteur(s)',
				value = 'auteur',
			},

			-- Dernière version
			{
				type  = 'row',
				label = 'Dernière version',
				value = 'dernière version',
			},

			-- Licence
			{
				type  = 'row',
				label = 'Licence',
				value = 'licence',
			},

			-- Téléchargement
			{
				type  = 'row',
				label = 'Téléchargement',
				value = 'download',
			},

			-- Version MediaWiki requise
			{
				type  = 'row',
				label = 'MediaWiki',
				value = 'mediawiki',
			},

			-- Version PHP requise
			{
				type  = 'row',
				label = 'PHP',
				value = 'php',
			},

			-- Exemple de wiki
			{
				type  = 'row',
				label = 'Exemple',
				value = 'exemple',
			},

		}, -- rows
	}, -- table

}

return p
