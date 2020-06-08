#!/bin/bash
git subtree push --prefix=src/Webiik/App App master --squash
git subtree push --prefix=src/Webiik/TemplateHelpers TemplateHelpers master --squash
git subtree push --prefix=src/Webiik/TwigExtension TwigExtension master --squash